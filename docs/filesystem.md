# Driver de Storage

`AzureBlob\Filesystem` — o driver `azure-blob` para `Storage::disk()`.

## Configuração

```php
// config/filesystems.php
'azure' => [
    'driver'     => 'azure-blob',
    'connection' => 'default',   // conexão de config/azure-blob.php
    'container'  => null,        // opcional: sobrescreve o container da conexão
    'root'       => '',          // prefixo aplicado a todos os caminhos
],
```

O disco também aceita credenciais próprias, sem passar por
`config/azure-blob.php` — basta informar `sas_url`, `connection_string` ou `key`:

```php
'azure' => [
    'driver'    => 'azure-blob',
    'sas_url'   => env('OUTRA_CONTA_SAS_URL'),
    'container' => 'outro-container',
],
```

## Dois adaptadores

| Laravel | Flysystem | Classe |
|---|---|---|
| 9 a 13 | 3.x | `AzureBlobAdapter` |
| 8 | 1.x | `AzureBlobAdapterV1` |

Os contratos são incompatíveis entre si — o Flysystem 1 devolve arrays e
sinaliza falha com `false`, o 3 devolve objetos e lança exceção — então não há
como uma classe só satisfazer os dois. `AzureBlobServiceProvider::makeFilesystem()`
escolhe em tempo de execução, testando `interface_exists()`.

`AzureBlobAdapterV1` **só é carregada** quando o Flysystem 1 está instalado; sob
o 3, a interface que ela implementa não existe e referenciá-la seria fatal.

## Diferenças de comportamento por versão

Vêm do framework, não do pacote:

| Operação | Laravel 8 | Laravel 9+ |
|---|---|---|
| `get()` de blob ausente | lança `FileNotFoundException` | devolve `null` |
| `delete()` de blob ausente | devolve `false` | devolve `true` |
| `copy()` | confere origem presente e destino ausente antes (2 HEAD extras) | copia direto |

## Visibilidade

Não é suportada por blob, e isso é deliberado: no Azure o nível de acesso
público é do **container**, não do blob. `setVisibility()` lança
`UnableToSetVisibility` em vez de fingir sucesso numa chamada sem efeito.
Configure o container pelo portal ou pela CLI do Azure.

## Diretórios

Não existem no Azure — são apenas barras dentro do nome do blob.
`makeDirectory()` é um no-op bem-sucedido e não gera requisição;
`deleteDirectory()` lista o prefixo e apaga item a item.

## URLs

`Storage::url()` e `Storage::temporaryUrl()` funcionam porque o adaptador expõe
`getUrl()` e `getTemporaryUrl()` — o Laravel detecta ambos por `method_exists`,
então as assinaturas precisam ficar exatamente como estão.

```php
Storage::disk('azure')->temporaryUrl('a.pdf', now()->addHour());
Storage::disk('azure')->temporaryUrl('a.pdf', now()->addHour(), ['permissions' => 'rw']);
```
