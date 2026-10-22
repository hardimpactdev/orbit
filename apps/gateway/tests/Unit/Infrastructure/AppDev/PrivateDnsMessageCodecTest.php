<?php

declare(strict_types=1);

use App\Domain\AppDev\DnsRecordType;
use App\Domain\AppDev\PrivateDnsAnswer;
use App\Infrastructure\AppDev\PrivateDnsMessageCodec;

it('decodes a question and ignores an EDNS client subnet identity', function (): void {
    $codec = new PrivateDnsMessageCodec;
    $message = $codec->encodeQuery('app.cluster.test', DnsRecordType::A, id: 42, ednsClientSubnet: '10.44.0.9');

    $query = $codec->decodeQuestion($message);

    expect($query->id)
        ->toBe(42)
        ->and($query->question->normalizedName())
        ->toBe('app.cluster.test')
        ->and($query->question->type)
        ->toBe(DnsRecordType::A)
        ->and($query->contentIdentity)
        ->toBe('10.44.0.9');
});

it('rejects a DNS name with an empty or overlong label', function (): void {
    $codec = new PrivateDnsMessageCodec;

    expect(fn (): string => $codec->encodeQuery('app..cluster.test'))
        ->toThrow(InvalidArgumentException::class, 'DNS label is not encodable.')
        ->and(fn (): string => $codec->encodeQuery(str_repeat('a', 64).'.test'))
        ->toThrow(InvalidArgumentException::class, 'DNS label is not encodable.');
});

it('encodes a DNS name whose longest label is 63 characters', function (): void {
    $codec = new PrivateDnsMessageCodec;
    $name = str_repeat('a', 63).'.test';

    $query = $codec->decodeQuestion($codec->encodeQuery($name));

    expect($query->question->normalizedName())->toBe($name);
});

it('encodes an authoritative A answer for the asked name', function (): void {
    $codec = new PrivateDnsMessageCodec;
    $query = $codec->decodeQuestion($codec->encodeQuery('gateway.orbit'));

    $response = $codec->encodeAnswer($query, PrivateDnsAnswer::a('10.44.0.1'));
    $decoded = $codec->decodeQuestion($response);
    $address = unpack('Nip', substr($response, -4));

    expect($decoded->question->normalizedName())
        ->toBe('gateway.orbit')
        ->and($address['ip'] ?? null)
        ->toBe(ip2long('10.44.0.1'));
});

it('decodes compressed A answers and ignores records for another name', function (): void {
    $codec = new PrivateDnsMessageCodec;
    $request = $codec->encodeQuery('sync.example.com', id: 42);
    $question = substr($request, 12);
    $record = "\xc0\x0c".pack('nnNn', 1, 1, 30, 4).inet_pton('10.44.0.4');
    $other = "\x05other\x07example\x03com\0".pack('nnNn', 1, 1, 30, 4).inet_pton('10.44.0.7');
    $response = pack('nnnnnn', 42, 0x8400, 1, 3, 0, 0).$question.$record.$other.$record;

    expect($codec->decodeAAnswer($response, $request))->toBe(['10.44.0.4']);
});

it('decodes a missing managed DNS record as no addresses', function (): void {
    $codec = new PrivateDnsMessageCodec;
    $request = $codec->encodeQuery('sync.example.com', id: 42);
    $response = $codec->encodeAnswer($codec->decodeQuestion($request), PrivateDnsAnswer::empty());

    expect($codec->decodeAAnswer($response, $request))->toBe([]);
});

it('rejects an invalid DNS response instead of reporting drift', function (string $defect): void {
    $codec = new PrivateDnsMessageCodec;
    $request = $codec->encodeQuery('sync.example.com', id: 42);
    $response = $codec->encodeAnswer($codec->decodeQuestion($request), PrivateDnsAnswer::a('10.44.0.4'));
    $response = match ($defect) {
        'transaction' => substr_replace($response, pack('n', 43), 0, 2),
        'question' => str_replace('sync', 'oops', $response),
        'type' => substr_replace($response, pack('n', 15), strlen($request) - 4, 2),
        'failure' => substr_replace($response, pack('n', 0x8002), 2, 2),
        'truncation flag' => substr_replace($response, pack('n', 0x8200), 2, 2),
        'truncated data' => substr($response, 0, -1),
        'pointer cycle' => substr_replace($response, "\xc0\x0c", 12, 2),
    };

    expect(fn (): array => $codec->decodeAAnswer($response, $request))->toThrow(InvalidArgumentException::class);
})->with(['transaction', 'question', 'type', 'failure', 'truncation flag', 'truncated data', 'pointer cycle']);
