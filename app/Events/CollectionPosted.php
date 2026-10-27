<?php

namespace App\Events;

use App\Models\CollectionBatch;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Step 21 — fired after a collection batch posts. Step 40 attaches the
 * SendCollectionReceipt listener that SMSes the member a receipt from
 * the `collection.receipt.bn/en` templates; no SMS code lives here.
 */
class CollectionPosted
{
    use Dispatchable, SerializesModels;

    public function __construct(public CollectionBatch $batch) {}
}
