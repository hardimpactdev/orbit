<?php

declare(strict_types=1);

namespace Orbit\Core\Operations;

enum OperationStreamFrameType: string
{
    case Tree = 'tree';
    case Step = 'step';
    case Complete = 'complete';
    case Error = 'error';
    case Stdout = 'stdout';
    case Stderr = 'stderr';
    case Status = 'status';
    case Control = 'control';
    case Terminal = 'terminal';

    /**
     * @return list<string>
     */
    public static function gatewayValues(): array
    {
        return [
            self::Tree->value,
            self::Step->value,
            self::Complete->value,
            self::Error->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function nodeValues(): array
    {
        return [
            self::Stdout->value,
            self::Stderr->value,
            self::Status->value,
            self::Control->value,
            self::Error->value,
            self::Terminal->value,
        ];
    }

    public function isGateway(): bool
    {
        return in_array($this->value, self::gatewayValues(), true);
    }

    public function isNode(): bool
    {
        return in_array($this->value, self::nodeValues(), true);
    }
}
