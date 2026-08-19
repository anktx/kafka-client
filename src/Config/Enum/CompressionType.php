<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Config\Enum;

enum CompressionType: string
{
    case None = 'none';
    case Snappy = 'snappy';
    case Gzip = 'gzip';
    case Lz4 = 'lz4';
    case Zstd = 'zstd';
}
