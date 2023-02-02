<?php declare(strict_types=1);

namespace Amp\Http\Server\StaticContent\Internal;

enum Precondition
{
    case NotModified;
    case Failed;
    case IfRangeOk;
    case IfRangeFailed;
    case Ok;
}
