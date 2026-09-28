<?php

namespace AzureBlob\Support;

/**
 * Normalização de nomes de blob.
 *
 * O Azure trata o nome do blob como uma string opaca — "pastas" são só barras
 * dentro do nome. O que precisa de cuidado é o percent-encoding: cada segmento
 * é codificado, mas as barras que os separam não.
 */
final class Path
{
    /**
     * Codifica um nome de blob para uso em URL, preservando as barras.
     *
     * `rawurlencode()` sozinho transformaria `a/b.txt` em `a%2Fb.txt`, o que o
     * Azure interpretaria como um blob de nome literal `a/b.txt` — o mesmo
     * destino por acaso, mas a assinatura Shared Key não bateria.
     */
    public static function encode(string $blob): string
    {
        return implode('/', array_map('rawurlencode', explode('/', self::normalize($blob))));
    }

    /**
     * Codifica cada segmento sem normalizar — para nomes que vieram do próprio
     * Azure (listagem) e precisam ser endereçados exatamente como estão.
     */
    public static function encodeRaw(string $blob): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $blob)));
    }

    /**
     * Remove barras iniciais e colapsa barras duplicadas.
     *
     * `/pasta//arquivo.txt` e `pasta/arquivo.txt` devem apontar para o mesmo
     * blob; sem isso o Azure criaria dois blobs distintos.
     */
    public static function normalize(string $blob): string
    {
        $blob = str_replace('\\', '/', trim($blob));
        $blob = (string) preg_replace('#/+#', '/', $blob);

        return ltrim($blob, '/');
    }

    /**
     * Prefixo de "diretório", sempre terminado em barra (ou vazio na raiz).
     */
    public static function directoryPrefix(string $path): string
    {
        $path = trim(self::normalize($path), '/');

        return $path === '' ? '' : $path.'/';
    }

    /** Nome do arquivo, sem o caminho. */
    public static function basename(string $blob): string
    {
        $normalized = self::normalize($blob);
        $position = strrpos($normalized, '/');

        return $position === false ? $normalized : substr($normalized, $position + 1);
    }

    /** Caminho do "diretório" que contém o blob, sem barra final. */
    public static function dirname(string $blob): string
    {
        $normalized = self::normalize($blob);
        $position = strrpos($normalized, '/');

        return $position === false ? '' : substr($normalized, 0, $position);
    }
}
