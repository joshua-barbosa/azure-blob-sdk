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

    /** Status HTTP da resposta que originou a falha, quando houver. */
    public function status(): ?int
    {
        $status = $this->context['status'] ?? null;

        return $status === null ? null : (int) $status;
    }
}
