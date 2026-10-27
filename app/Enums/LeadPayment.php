<?php

namespace App\Enums;

/**
 * How the buyer means to pay, as the agent heard it.
 */
enum LeadPayment: string
{
    case Mortgage = 'mortgage';

    case Cash = 'cash';

    case Installment = 'installment';
}
