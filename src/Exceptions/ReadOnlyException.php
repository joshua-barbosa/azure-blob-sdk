<?php

namespace AzureBlob\Exceptions;

/**
 * Lançada ao tentar escrever numa conexão marcada como somente leitura.
 *
 * A trava é local ao SDK: serve para impedir que um container de produção seja
 * alterado por engano, não substitui as permissões do próprio SAS token.
 */
class ReadOnlyException extends AzureBlobException
{
    public static function for(string $connection, string $operation): self
    {
        return new self(
            sprintf(
                'azure-blob [%s]: operação "%s" bloqueada — a conexão está em modo somente leitura.',
                $connection,
                $operation
            ),
            0,
            null,
            ['connection' => $connection, 'operation' => $operation]
        );
    }
}
