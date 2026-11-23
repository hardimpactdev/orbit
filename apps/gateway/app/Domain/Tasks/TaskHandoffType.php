<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/** Who handed off and how ([Handoffs](/reference/tasks#handoffs)). */
enum TaskHandoffType: string
{
    case Delivery = 'delivery';
    case Review = 'review';
    case Question = 'question';
}
