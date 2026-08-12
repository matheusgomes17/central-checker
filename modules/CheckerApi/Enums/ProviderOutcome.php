<?php

declare(strict_types=1);

namespace Modules\CheckerApi\Enums;

/**
 * The three ways a provider call can end.
 *
 * The distinction is the billing rule in disguise: only Success may be charged.
 * NoData is a legitimate answer ("no record found"), not a failure, so it must
 * not be confused with Failed, which feeds the circuit breaker.
 */
enum ProviderOutcome: string
{
    case Success = 'success';
    case NoData = 'no_data';
    case Failed = 'failed';
}
