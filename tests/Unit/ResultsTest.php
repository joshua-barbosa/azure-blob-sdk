<?php

namespace AzureBlob\Tests\Unit;

use AzureBlob\Exceptions\AzureBlobException;
use AzureBlob\Results\BlobContent;
use AzureBlob\Results\BlobList;
use AzureBlob\Results\BlobProperties;
use PHPUnit\Framework\TestCase;

class ResultsTest extends TestCase
{
    private const CONTAINER_URL = 'https://contateste.blob.core.windows.net/meu-container';

    private function listXml(string $nextMarker = ''): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<EnumerationResults ContainerName="meu-container"><Prefix>2026/</Prefix><Blobs>'
            .'<Blob><Name>2026/a.pdf</Name><Properties>'
            .'<Creation-Time>Mon, 05 Jan 2026 10:00:00 GMT</Creation-Time>'
            .'<Last-Modified>Tue, 06 Jan 2026 12:30:00 GMT</Last-Modified>'
            .'<Etag>"0x8DAA"</Etag><Content-Length>2048</Content-Length>'
            .'<Content-Type>application/pdf</Content-Type><BlobType>BlockBlob</BlobType>'
            .'</Properties></Blob>'
            .'<Blob><Name>2026/b com espaço.txt</Name><Properties>'
            .'<Content-Length>10</Content-Length><Content-Type>text/plain</Content-Type>'
            .'</Properties></Blob>'
            .'<BlobPrefix><Name>2026/sub/</Name></BlobPrefix>'
            .'</Blobs><NextMarker>'.$nextMarker.'</NextMarker></EnumerationResults>';
    }

    public function test_hidrata_blobs_a_partir_do_xml(): void
    {
        $list = BlobList::fromXml($this->listXml(), self::CONTAINER_URL, 'meu-container');

        $this->assertCount(2, $list);
        $this->assertSame(['2026/a.pdf', '2026/b com espaço.txt'], $list->names());
        $this->assertSame('2026/', $list->prefix);
        $this->assertSame('meu-container', $list->container);

        $primeiro = $list->items[0];
        $this->assertSame(2048, $primeiro->size);
        $this->assertSame('application/pdf', $primeiro->contentType);
        $this->assertSame('0x8DAA', $primeiro->etag);
        $this->assertSame('BlockBlob', $primeiro->blobType);
        $this->assertSame('2026-01-06T12:30:00+00:00', $primeiro->lastModified->format('c'));
        $this->assertSame('2026-01-05T10:00:00+00:00', $primeiro->createdOn->format('c'));
    }

    public function test_url_do_item_codifica_o_nome(): void
    {
        $list = BlobList::fromXml($this->listXml(), self::CONTAINER_URL);

        $this->assertSame(
            self::CONTAINER_URL.'/2026/b%20com%20espa%C3%A7o.txt',
            $list->items[1]->url
        );
    }

    public function test_blob_prefix_vira_diretorio(): void
    {
        $list = BlobList::fromXml($this->listXml(), self::CONTAINER_URL);

        $this->assertCount(1, $list->directories);
        $this->assertTrue($list->directories[0]->isDirectory);
        // A barra final some: o nome do "diretório" é o prefixo sem ela.
        $this->assertSame('2026/sub', $list->directories[0]->name);
    }

    public function test_next_marker_vazio_vira_null(): void
    {
        $this->assertNull(BlobList::fromXml($this->listXml(), self::CONTAINER_URL)->nextMarker);
        $this->assertFalse(BlobList::fromXml($this->listXml(), self::CONTAINER_URL)->hasMore());

        $comMarker = BlobList::fromXml($this->listXml('marca123'), self::CONTAINER_URL);
        $this->assertSame('marca123', $comMarker->nextMarker);
        $this->assertTrue($comMarker->hasMore());
    }

    public function test_agrega_tamanho_total_e_e_iteravel(): void
    {
        $list = BlobList::fromXml($this->listXml(), self::CONTAINER_URL);

        $this->assertSame(2058, $list->totalSize());
        $this->assertFalse($list->isEmpty());
        $this->assertSame(2, iterator_count($list));
        $this->assertSame(2, $list->collect()->count());
        $this->assertCount(3, $list->all());
    }

    public function test_lista_vazia(): void
    {
        $list = BlobList::fromXml(
            '<?xml version="1.0"?><EnumerationResults><Blobs/><NextMarker/></EnumerationResults>',
            self::CONTAINER_URL
        );

        $this->assertTrue($list->isEmpty());
        $this->assertSame(0, $list->totalSize());
    }

    public function test_item_expoe_basename_dirname_e_tamanho_legivel(): void
    {
        $item = BlobList::fromXml($this->listXml(), self::CONTAINER_URL)->items[0];

        $this->assertSame('a.pdf', $item->basename());
        $this->assertSame('2026', $item->dirname());
        $this->assertSame('2.0 KB', $item->humanSize());
        $this->assertSame('2026/a.pdf', $item->toArray()['name']);
        $this->assertSame($item->toArray(), $item->jsonSerialize());
    }

    public function test_to_array_da_lista_resume_a_pagina(): void
    {
        $list = BlobList::fromXml($this->listXml('marca'), self::CONTAINER_URL, 'meu-container');

        $array = $list->toArray();

        $this->assertSame('meu-container', $array['container']);
        $this->assertSame('2026/', $array['prefix']);
        $this->assertSame(2, $array['count']);
        $this->assertSame(2058, $array['total_size']);
        $this->assertSame('marca', $array['next_marker']);
        $this->assertCount(1, $array['directories']);
        $this->assertCount(2, $array['blobs']);
        $this->assertSame($array, $list->jsonSerialize());
    }

    public function test_data_ilegivel_vira_null_sem_estourar(): void
    {
        $xml = '<?xml version="1.0"?><EnumerationResults><Blobs><Blob><Name>a</Name>'
            .'<Properties><Last-Modified>nao-e-data</Last-Modified></Properties></Blob></Blobs></EnumerationResults>';

        $this->assertNull(BlobList::fromXml($xml, self::CONTAINER_URL)->items[0]->lastModified);
    }

    // ========================================================================
    // BlobProperties
    // ========================================================================

    public function test_propriedades_saem_dos_cabecalhos(): void
    {
        $properties = BlobProperties::fromHeaders([
            'Content-Length' => ['4096'],
            'Content-Type' => ['application/pdf'],
            'Last-Modified' => ['Tue, 06 Jan 2026 12:30:00 GMT'],
            'ETag' => ['"0x8DAABBCC"'],
            'x-ms-blob-type' => ['BlockBlob'],
            'x-ms-creation-time' => ['Mon, 05 Jan 2026 10:00:00 GMT'],
            'x-ms-access-tier' => ['Hot'],
            'x-ms-meta-origem' => ['upload-web'],
            'x-ms-meta-usuario' => ['42'],
        ], 'a.pdf', 'meu-container', 'https://exemplo/a.pdf');

        $this->assertSame(4096, $properties->size);
        $this->assertSame('application/pdf', $properties->contentType);
        $this->assertSame('0x8DAABBCC', $properties->etag);
        $this->assertSame('Hot', $properties->accessTier);
        $this->assertSame(['origem' => 'upload-web', 'usuario' => '42'], $properties->metadata);
        $this->assertSame('4.0 KB', $properties->humanSize());
        $this->assertSame('2026-01-06T12:30:00+00:00', $properties->lastModified->format('c'));
    }

    public function test_cabecalhos_sao_lidos_sem_diferenciar_maiusculas(): void
    {
        $properties = BlobProperties::fromHeaders(
            ['content-length' => '10', 'CONTENT-TYPE' => 'text/plain'],
            'a.txt',
            'c',
            'https://exemplo/a.txt'
        );

        $this->assertSame(10, $properties->size);
        $this->assertSame('text/plain', $properties->contentType);
    }

    public function test_cabecalhos_ausentes_viram_null(): void
    {
        $properties = BlobProperties::fromHeaders([], 'a.txt', 'c', 'https://exemplo/a.txt');

        $this->assertSame(0, $properties->size);
        $this->assertNull($properties->contentType);
        $this->assertNull($properties->lastModified);
        $this->assertSame([], $properties->metadata);
        $this->assertArrayHasKey('url', $properties->toArray());
    }

    // ========================================================================
    // BlobContent
    // ========================================================================

    public function test_conteudo_textual_e_transportado_como_texto(): void
    {
        $content = new BlobContent('olá mundo', 'a.txt', 'text/plain; charset=utf-8', 10);

        $this->assertTrue($content->isText());
        $this->assertSame(BlobContent::ENCODING_TEXT, $content->encoding());
        $this->assertSame('olá mundo', $content->toArray()['content']);
        $this->assertSame('olá mundo', (string) $content);
    }

    public function test_conteudo_binario_e_transportado_em_base64(): void
    {
        $bytes = "\x00\x01\x02\xFF";
        $content = new BlobContent($bytes, 'a.bin', 'application/octet-stream', 4);

        $this->assertFalse($content->isText());
        $this->assertSame(BlobContent::ENCODING_BASE64, $content->encoding());
        $this->assertSame(base64_encode($bytes), $content->toArray()['content']);
        $this->assertSame($bytes, $content->contents());
    }

    public function test_tipo_textual_com_bytes_invalidos_cai_para_base64(): void
    {
        // text/plain em latin-1 não é UTF-8 válido; mandar como texto quebraria o JSON.
        $content = new BlobContent("caf\xE9", 'a.txt', 'text/plain', 4);

        $this->assertFalse($content->isText());
    }

    public function test_reconhece_sufixos_json_e_xml(): void
    {
        $this->assertTrue((new BlobContent('{}', 'a', 'application/vnd.api+json'))->isText());
        $this->assertTrue((new BlobContent('<a/>', 'a', 'image/svg+xml'))->isText());
    }

    public function test_json_decodifica_o_conteudo(): void
    {
        $content = new BlobContent('{"a":1,"b":[2,3]}', 'a.json', 'application/json', 17);

        $this->assertSame(['a' => 1, 'b' => [2, 3]], $content->json());
        $this->assertSame(1, $content->json(false)->a);
    }

    public function test_json_invalido_lanca_excecao_com_o_nome_do_blob(): void
    {
        $this->expectException(AzureBlobException::class);
        $this->expectExceptionMessage('a.json');

        (new BlobContent('{invalido', 'a.json', 'application/json'))->json();
    }

    public function test_save_to_grava_o_conteudo_em_disco(): void
    {
        $path = sys_get_temp_dir().'/azure-blob-teste-'.uniqid().'.txt';

        $bytes = (new BlobContent('conteudo', 'a.txt', 'text/plain', 8))->saveTo($path);

        $this->assertSame(8, $bytes);
        $this->assertSame('conteudo', file_get_contents($path));

        unlink($path);
    }

    public function test_save_to_em_caminho_invalido_lanca_excecao(): void
    {
        $this->expectException(AzureBlobException::class);

        (new BlobContent('x', 'a.txt'))->saveTo('/caminho/que/nao/existe/a.txt');
    }
}
