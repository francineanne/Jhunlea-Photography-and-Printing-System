<?php

namespace App\Services\Assistant\Owner\Tools;

use App\Models\User;
use App\Services\Assistant\Owner\OwnerTool;
use App\Services\Assistant\Owner\ToolResult;

/**
 * How to do something in the admin pages, from config assistant.owner.how_to (fixed steps written by the team), with a
 * link to the page that does it. Lumi never does the action itself.
 */
class HowTo extends OwnerTool
{
    public function name(): string
    {
        return 'how_to';
    }

    public function description(): string
    {
        return 'Steps to do something in the admin pages, with a link to the page: record a payment at the counter, the payment receipt, a walk-in order or booking, void a payment, the income report (Daily, Weekly, Monthly), the end-of-day summary, the CSV, printing the report, what awaiting payment means, official receipts, BIR or taxes (the system\'s receipt is not official), confirm a booking, change hours and more. Lumi never does it itself.';
    }

    public function parameters(): array
    {
        return ['topic' => ['type' => 'string', 'required' => true, 'enum' => array_keys((array) config('assistant.owner.how_to'))]];
    }

    protected function handle(array $params, User $owner): ToolResult
    {
        $topic = config('assistant.owner.how_to.'.$params['topic']);
        $result = new ToolResult($this->name(), ['topic' => $params['topic'], 'steps' => $topic['steps']]);
        $result->link($topic['page'], route($topic['route']));

        // The step numbers ("1.", "2.") may be written in the answer
        foreach (array_keys($topic['steps']) as $index) {
            $result->number($index + 1);
        }

        return $result;
    }
}
