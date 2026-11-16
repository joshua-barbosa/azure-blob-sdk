<?php

namespace AzureBlob\Exceptions;

use Exception;
use Throwable;

/**
 * Exceção base do SDK.
 *
 * Carrega um contexto estruturado (status HTTP, código de erro do Azure, nome
 * do blob) para que quem captura possa decidir sem precisar reparsear mensagem.
 */
class AzureBlobException extends Exception
{
    /** @var array<string,mixed> */
    private array $context;

    /**
     * @param  array<string,mixed>  $context
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);

        $this->context = $context;
    }

    /** @return array<string,mixed> */
    public function context(): array
    {
        return $this->context;
    }

    /** Código de erro devolvido pelo Azure (ex.: `BlobNotFound`), quando houver. */
    public function errorCode(): ?string
    {
        $code = $this->context['error_code'] ?? null;

        return $code === null ? null : (string) $code;
    }

    /**
     * `x-ms-request-id` da resposta: o identificador que o Azure atribuiu à
     * requisição e que o suporte da Microsoft pede para localizá-la.
     */
    public function requestId(): ?string
    {
        $id = $this->context['request_id'] ?? null;

        return $id === null ? null : (string) $id;
    }

    /**
     * `x-ms-client-request-id` enviado pelo SDK. Aparece nos logs de
     * diagnóstico da conta e existe mesmo quando o Azure não chegou a responder.
     */
    public function clientRequestId(): ?string
    {
        $id = $this->context['client_request_id'] ?? null;

        return $id === null ? null : (string) $id;
    }

    /** Status HTTP da resposta que originou a falha, quando houver. */
    public function status(): ?int
    {
        $status = $this->context['status'] ?? null;

        return $status === null ? null : (int) $status;
    }
}
