<?php

namespace ItkDev\OpenIdConnectBundle\Exception;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The identity provider refused the authorization request.
 *
 * RFC 6749 §4.1.2.1: a provider that will not issue a code redirects back to the
 * callback carrying `error`, and optionally `error_description`, instead. That is
 * not a failure of ours — our request was well formed and the provider declined
 * it — so it is a distinct type from the validation failures around it, and it
 * carries the provider's error code as an accessor rather than as message text a
 * consumer would have to match on.
 *
 * Extends `AuthenticationFailedException` so that everything already catching the
 * bundle's login failure keeps catching this one, and so that it inherits the
 * fail-closed behaviour that keeps a failed callback from being retried. See
 * docs/adr/004-handle-provider-error-callbacks.md.
 *
 * Implementing `HttpExceptionInterface` lets the kernel answer a refusal with a
 * status that matches who is at fault. Most refusals are a user who changed their
 * mind, and answering those with a 500 pages somebody for a normal outcome. The
 * status is metadata the kernel reads, not a response this bundle renders.
 */
class ProviderErrorException extends AuthenticationFailedException implements HttpExceptionInterface
{
    /**
     * The provider refused because the user, or a policy standing in for them, said no.
     */
    public const string ACCESS_DENIED = 'access_denied';

    /**
     * @param string      $error            RFC 6749 §4.1.2.1 error code, already sanitized
     * @param string|null $errorDescription the provider's description, already sanitized
     */
    public function __construct(
        private readonly string $error,
        private readonly ?string $errorDescription = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            null === $errorDescription
                ? sprintf('The identity provider refused the request: %s', $error)
                : sprintf('The identity provider refused the request: %s (%s)', $error, $errorDescription),
            0,
            $previous,
        );
    }

    /**
     * The provider's error code, sanitized and never empty.
     */
    public function getError(): string
    {
        return $this->error;
    }

    /**
     * The provider's description of the error, sanitized, or null if it sent none usable.
     */
    public function getErrorDescription(): ?string
    {
        return $this->errorDescription;
    }

    public function getStatusCode(): int
    {
        return match ($this->error) {
            // The user, or a policy standing in for them, said no. Nothing is
            // broken and nobody needs paging.
            self::ACCESS_DENIED, 'login_required', 'consent_required',
            'interaction_required', 'account_selection_required' => Response::HTTP_FORBIDDEN,
            // The provider is having a bad day and says so — the same answer
            // LoginController gives when it cannot reach one at all.
            'server_error', 'temporarily_unavailable' => Response::HTTP_SERVICE_UNAVAILABLE,
            // Everything else says our request or our registration was wrong:
            // invalid_request, unauthorized_client, invalid_scope, and whatever a
            // given provider invents. That is an operator's problem, and 500 is how
            // the application already reports one.
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
