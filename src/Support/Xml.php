<?php

namespace AzureBlob\Support;

use AzureBlob\Exceptions\AzureBlobException;
use SimpleXMLElement;
use Throwable;

/**
 * Leitura e escrita do XML da REST API do Blob Storage.
 *
 * O Azure não oferece JSON para as operações de blob: listagem, erros e commit
 * de blocos são todos XML.
 */
final class Xml
{
    /**
     * @throws AzureBlobException
     */
    public static function parse(string $xml): SimpleXMLElement
    {
        $xml = trim($xml);

        if ($xml === '') {
            throw new AzureBlobException('azure-blob: resposta XML vazia do Azure.');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            // LIBXML_NONET impede que uma DTD na resposta dispare requisição de
            // rede; sem entidades externas não há vetor de XXE.
            $parsed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } catch (Throwable $exception) {
            throw new AzureBlobException('azure-blob: XML inválido na resposta do Azure.', 0, $exception);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($parsed === false) {
            throw new AzureBlobException('azure-blob: XML inválido na resposta do Azure.');
        }

        return $parsed;
    }

    /**
     * Extrai `Code` e `Message` de um corpo de erro do Azure.
     *
     * Erros também chegam nos cabeçalhos `x-ms-error-code`; o corpo é o único
     * lugar com a mensagem legível.
     *
     * @return array{code:?string,message:?string}
     */
    public static function error(string $body): array
    {
        if (trim($body) === '' || ! str_contains($body, '<Error')) {
            return ['code' => null, 'message' => null];
        }

        try {
            $parsed = self::parse($body);
        } catch (AzureBlobException) {
            return ['code' => null, 'message' => null];
        }

        $code = isset($parsed->Code) ? trim((string) $parsed->Code) : '';
        $message = isset($parsed->Message) ? trim((string) $parsed->Message) : '';

        // A mensagem do Azure vem com "\nRequestId:...\nTime:..." anexado.
        $message = trim(explode("\n", $message)[0]);

        return [
            'code' => $code === '' ? null : $code,
            'message' => $message === '' ? null : $message,
        ];
    }

    /**
     * Monta o corpo de `Put Block List`.
     *
     * Todos os blocos vão como `Latest`, que resolve para a versão recém-enviada
     * independentemente de existir um bloco commitado com o mesmo id.
     *
     * @param  array<int,string>  $blockIds  Ids já em base64.
     */
    public static function blockList(array $blockIds): string
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>'."\n".'<BlockList>'."\n";

        foreach ($blockIds as $id) {
            $body .= '  <Latest>'.htmlspecialchars($id, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</Latest>'."\n";
        }

        return $body.'</BlockList>';
    }
}
