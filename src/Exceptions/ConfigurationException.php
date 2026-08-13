<?php

namespace AzureBlob\Exceptions;

/**
 * Lançada quando a conexão não tem credenciais suficientes ou aponta para um
 * container indefinido. Indica erro de configuração, não falha de comunicação.
 */
class ConfigurationException extends AzureBlobException
{
    public static function missingCredentials(string $connection): self
    {
        return new self(sprintf(
            'azure-blob [%s]: nenhuma credencial configurada. Defina um destes modos de '
            .'autenticação: "sas_url" (recomendado), "connection_string", ou "name" + "key".',
            $connection
        ));
    }

    public static function missingContainer(string $connection): self
    {
        return new self(sprintf(
            'azure-blob [%s]: container não configurado. Defina "container" na conexão '
            .'ou inclua o container na SAS URL.',
            $connection
        ));
    }

    public static function unknownConnection(string $connection): self
    {
        return new self(sprintf(
            'azure-blob: conexão "%s" não encontrada em config/azure-blob.php.',
            $connection
        ));
    }

    public static function invalidSasUrl(string $connection): self
    {
        return new self(sprintf(
            'azure-blob [%s]: "sas_url" inválida. Esperado '
            .'"https://<conta>.blob.core.windows.net/<container>?<sas_token>".',
            $connection
        ));
    }

    public static function signingUnavailable(string $connection): self
    {
        return new self(sprintf(
            'azure-blob [%s]: esta operação exige a chave da conta. Configure "name" + "key" '
            .'ou uma connection string com AccountName/AccountKey.',
            $connection
        ));
    }
}
