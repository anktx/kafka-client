<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Config\Enum;

enum CompressionType: string
{
    case none = 'none';
    case snappy = 'snappy';
    case gzip = 'gzip';
    case lz4 = 'lz4';
    case zstd = 'zstd';
}
