<?php

namespace App\Exceptions;

use Exception;
use Google\Service\Exception as GoogleServiceException;

class GoogleGatewayException extends Exception
{
    public static function fromGoogle(GoogleServiceException $exception): self
    {
        $reason = (string) data_get($exception->getErrors(), '0.reason');
        $message = match ($reason) {
            'accessNotConfigured' => 'l’API Google richiesta non è abilitata sul progetto Google Cloud',
            'authError', 'invalidCredentials', 'unauthorized' => 'l’autorizzazione Google non è più valida, ricollega l’account',
            'insufficientPermissions', 'forbidden' => 'l’account Google non ha concesso i permessi richiesti',
            'notFound' => 'la risorsa Google richiesta non esiste',
            'rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded' => 'il limite di richieste verso Google è stato superato',
            default => (string) (data_get($exception->getErrors(), '0.message')
                ?: "Google ha risposto con il codice {$exception->getCode()}"),
        };

        return new self($message, previous: $exception);
    }
}
