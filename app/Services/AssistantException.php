<?php

namespace App\Services;

/**
 * A user-facing failure of the AjvaAI assistant. The message is shown as is;
 * $details carries the raw technical text (e.g. Google's full error response)
 * for the "full error" modal. It never contains the API key or business data.
 */
class AssistantException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?string $details = null)
    {
        parent::__construct($message);
    }
}
