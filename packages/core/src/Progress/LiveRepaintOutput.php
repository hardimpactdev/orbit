<?php

declare(strict_types=1);

namespace Orbit\Core\Progress;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

final class LiveRepaintOutput
{
    public static function supports(OutputInterface $output): bool
    {
        $stream = self::resolveStream($output);

        if (is_resource($stream)
            && function_exists('posix_isatty')
            && posix_isatty($stream)) {
            return true;
        }

        return $output->isDecorated();
    }

    public static function resolveStream(OutputInterface $output): mixed
    {
        if ($output instanceof StreamOutput) {
            return $output->getStream();
        }

        if (method_exists($output, 'getOutput')) {
            $underlying = $output->getOutput();

            if ($underlying instanceof OutputInterface) {
                return self::resolveStream($underlying);
            }
        }

        if (defined('STDOUT') && is_resource(STDOUT)) {
            return STDOUT;
        }

        return null;
    }
}
