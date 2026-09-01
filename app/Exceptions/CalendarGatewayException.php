<?php

namespace App\Exceptions;

use Exception;
use Google\Service\Exception as GoogleServiceException;
use Throwable;

/**
 * Errore di un provider di calendario tradotto in un motivo leggibile,
 * così la chat può spiegare cosa è andato storto invece di un messaggio generico.
 */
class CalendarGatewayException extends Exception
{
    public static function fromGoogle(GoogleServiceException $exception): self
    {
        return new self(self::googleReason($exception), previous: $exception);
    }

    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self($reason, previous: $previous);
    }

    private static function googleReason(GoogleServiceException $exception): string
    {
        $reason = (string) data_get($exception->getErrors(), '0.reason');

        return match ($reason) {
            'accessNotConfigured' => 'l’API Google Calendar non è abilitata sul progetto Google Cloud',
            'authError', 'invalidCredentials', 'unauthorized' => 'l’autorizzazione Google non è più valida, ricollega l’account',
            'insufficientPermissions', 'forbidden' => 'l’account Google non ha i permessi sul calendario',
            'notFound' => 'il calendario richiesto non esiste',
            'rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded' => 'il limite di richieste verso Google è stato superato',
            default => self::googleMessage($exception),
        };
    }

    private static function googleMessage(GoogleServiceException $exception): string
    {
        $message = data_get($exception->getErrors(), '0.message');

        if (! is_string($message) || $message === '') {
            return sprintf('Google ha risposto con un errore (codice %d)', $exception->getCode());
        }

        return $message;
    }
}
