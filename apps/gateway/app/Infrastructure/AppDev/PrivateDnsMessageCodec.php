<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\DnsQuestion;
use App\Domain\AppDev\DnsRecordType;
use App\Domain\AppDev\PrivateDnsAnswer;
use App\Domain\AppDev\PrivateDnsAnswerExpiry;
use InvalidArgumentException;

final readonly class PrivateDnsMessageCodec
{
    private const int TypeOpt = 41;

    private const int OptionClientSubnet = 8;

    private const int ClassIn = 1;

    private const int Ttl = PrivateDnsAnswerExpiry::TtlSeconds;

    public function decodeQuestion(string $message): DecodedDnsQuery
    {
        if (strlen($message) < 12) {
            throw new InvalidArgumentException('DNS message is too short.');
        }

        $header = unpack('nid/nflags/nqdcount/nancount/nnscount/narcount', $message);
        if ($header === false || $header['qdcount'] < 1) {
            throw new InvalidArgumentException('DNS message has no question.');
        }

        $offset = 12;
        [$name, $offset] = $this->readName($message, $offset);
        if (strlen($message) < $offset + 4) {
            throw new InvalidArgumentException('DNS question is truncated.');
        }

        $questionFields = unpack('ntype/nclass', substr($message, $offset, 4));
        if ($questionFields === false) {
            throw new InvalidArgumentException('DNS question is truncated.');
        }

        $offset += 4;
        $type = DnsRecordType::tryFrom($questionFields['type']) ?? DnsRecordType::A;
        $contentIdentity = $this->ednsClientSubnet($message, $offset, $header['ancount'], $header['nscount'], $header['arcount']);

        return new DecodedDnsQuery(
            id: $header['id'],
            flags: $header['flags'],
            question: new DnsQuestion($name, $type, $questionFields['class']),
            contentIdentity: $contentIdentity,
        );
    }

    public function encodeAnswer(DecodedDnsQuery $query, PrivateDnsAnswer $answer): string
    {
        $flags = 0x8000;
        $flags |= $query->flags & 0x7800;
        $flags |= $query->flags & 0x0100;

        if ($answer->authoritative) {
            $flags |= 0x0400;
        }

        if ($answer->addresses === []) {
            $flags |= 0x0003;
        }

        $encodedName = $this->encodeName($query->question->name);
        $question = $encodedName.pack('nn', $query->question->type->value, $query->question->class);
        $records = '';

        foreach ($answer->addresses as $address) {
            $binary = inet_pton($address);
            if ($binary === false) {
                continue;
            }

            $records .= $encodedName.pack('nnNn', DnsRecordType::A->value, self::ClassIn, self::Ttl, strlen($binary)).$binary;
        }

        return pack(
            'nnnnnn',
            $query->id,
            $flags,
            1,
            count($answer->addresses),
            0,
            0,
        ).$question.$records;
    }

    /** @return list<string> */
    public function decodeAAnswer(string $message, string $request): array
    {
        $query = $this->decodeQuestion($request);
        $answer = $this->decodeQuestion($message);
        $header = unpack('nid/nflags/nqdcount/nancount/nnscount/narcount', $message);
        if ($header === false
            || $header['qdcount'] !== 1
            || ($header['flags'] & 0xFA00) !== 0x8000
            || ! in_array($header['flags'] & 0x000F, [0, 3], true)
            || $answer->id !== $query->id
            || $answer->question->normalizedName() !== $query->question->normalizedName()
            || $answer->question->type !== DnsRecordType::A
            || $answer->question->class !== self::ClassIn) {
            throw new InvalidArgumentException('DNS response does not answer the A query.');
        }

        [, $offset] = $this->readName($message, 12);
        if (substr($message, $offset, 4) !== pack('nn', DnsRecordType::A->value, self::ClassIn)) {
            throw new InvalidArgumentException('DNS response does not answer the A query.');
        }
        $offset += 4;
        $addresses = [];
        for ($index = 0; $index < $header['ancount']; $index++) {
            [$record, $offset] = $this->readRecord($message, $offset);
            if ($record['type'] !== DnsRecordType::A->value
                || $record['class'] !== self::ClassIn
                || strtolower($record['name']) !== $query->question->normalizedName()) {
                continue;
            }
            if (strlen($record['rdata']) !== 4) {
                throw new InvalidArgumentException('DNS A record is not an IPv4 address.');
            }
            $address = inet_ntop($record['rdata']);
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }
        $this->skipRecords($message, $offset, $header['nscount'] + $header['arcount']);

        return array_values(array_unique($addresses));
    }

    public function encodeQuery(string $name, DnsRecordType $type = DnsRecordType::A, int $id = 1, ?string $ednsClientSubnet = null): string
    {
        $encodedName = $this->encodeName($name);
        $question = $encodedName.pack('nn', $type->value, self::ClassIn);
        $additional = '';
        $arcount = 0;

        if (is_string($ednsClientSubnet)) {
            $additional = $this->encodeEdnsClientSubnet($ednsClientSubnet);
            $arcount = 1;
        }

        return pack('nnnnnn', $id, 0x0100, 1, 0, 0, $arcount).$question.$additional;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function readName(string $message, int $offset): array
    {
        $labels = [];
        $jumped = false;
        $cursor = $offset;
        $end = $offset;
        $visited = [];

        while ($cursor < strlen($message)) {
            if (isset($visited[$cursor])) {
                throw new InvalidArgumentException('DNS name pointer contains a cycle.');
            }
            $visited[$cursor] = true;
            $length = ord($message[$cursor]);

            if ($length === 0) {
                if (! $jumped) {
                    $end = $cursor + 1;
                }

                return [implode('.', $labels), $end];
            }

            if (($length & 0xC0) === 0xC0) {
                if ($cursor + 1 >= strlen($message)) {
                    throw new InvalidArgumentException('DNS name pointer is truncated.');
                }

                $pointer = (($length & 0x3F) << 8) | ord($message[$cursor + 1]);
                if (! $jumped) {
                    $end = $cursor + 2;
                    $jumped = true;
                }

                $cursor = $pointer;

                continue;
            }

            $cursor++;
            if ($cursor + $length > strlen($message)) {
                throw new InvalidArgumentException('DNS name is truncated.');
            }

            $labels[] = substr($message, $cursor, $length);
            $cursor += $length;
        }

        throw new InvalidArgumentException('DNS name is truncated.');
    }

    private function encodeName(string $name): string
    {
        $normalized = rtrim($name, '.');
        if ($normalized === '') {
            return "\0";
        }

        $encoded = '';
        foreach (explode('.', $normalized) as $label) {
            if ($label === '' || strlen($label) > 63) {
                throw new InvalidArgumentException('DNS label is not encodable.');
            }

            $encoded .= chr(strlen($label)).$label;
        }

        return $encoded."\0";
    }

    private function ednsClientSubnet(
        string $message,
        int $offset,
        int $ancount,
        int $nscount,
        int $arcount,
    ): ?string {
        $offset = $this->skipRecords($message, $offset, $ancount + $nscount);

        for ($index = 0; $index < $arcount; $index++) {
            [$record, $offset] = $this->readRecord($message, $offset);

            if ($record['type'] !== self::TypeOpt) {
                continue;
            }

            $address = $this->clientSubnetFromOptions($record['rdata']);
            if ($address !== null) {
                return $address;
            }
        }

        return null;
    }

    /**
     * @return array{0: array{name: string, type: int, class: int, rdata: string}, 1: int}
     */
    private function readRecord(string $message, int $offset): array
    {
        [$name, $offset] = $this->readName($message, $offset);
        if (strlen($message) < $offset + 10) {
            throw new InvalidArgumentException('DNS record is truncated.');
        }

        $fields = unpack('ntype/nclass/Nttl/nlength', substr($message, $offset, 10));
        if ($fields === false) {
            throw new InvalidArgumentException('DNS record is truncated.');
        }

        $offset += 10;
        $length = $fields['length'];
        if (strlen($message) < $offset + $length) {
            throw new InvalidArgumentException('DNS record data is truncated.');
        }

        return [
            [
                'name' => $name,
                'type' => $fields['type'],
                'class' => $fields['class'],
                'rdata' => substr($message, $offset, $length),
            ],
            $offset + $length,
        ];
    }

    private function skipRecords(string $message, int $offset, int $count): int
    {
        for ($index = 0; $index < $count; $index++) {
            [, $offset] = $this->readRecord($message, $offset);
        }

        return $offset;
    }

    private function clientSubnetFromOptions(string $options): ?string
    {
        $offset = 0;
        $length = strlen($options);

        while ($offset + 4 <= $length) {
            $header = unpack('ncode/nsize', substr($options, $offset, 4));
            if ($header === false) {
                return null;
            }

            $offset += 4;
            $size = $header['size'];
            if ($offset + $size > $length) {
                return null;
            }

            $data = substr($options, $offset, $size);
            $offset += $size;

            if ($header['code'] !== self::OptionClientSubnet || strlen($data) < 4) {
                continue;
            }

            $fields = unpack('nfamily/Csource/Cscope', substr($data, 0, 4));
            if ($fields === false || $fields['family'] !== 1) {
                continue;
            }

            $address = substr($data, 4);
            $padded = str_pad($address, 4, "\0");
            $decoded = inet_ntop($padded);

            if (is_string($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function encodeEdnsClientSubnet(string $address): string
    {
        $binary = inet_pton($address);
        if ($binary === false) {
            throw new InvalidArgumentException('EDNS client subnet is not an IP address.');
        }

        $option = pack('nnnCC', self::OptionClientSubnet, 8, 1, 32, 0).$binary;

        return "\0".pack('nnNn', self::TypeOpt, 1232, 0, strlen($option)).$option;
    }
}
