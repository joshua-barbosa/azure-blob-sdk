<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Conexão padrão
    |--------------------------------------------------------------------------
    |
    | Nome da conexão usada quando nenhuma é informada — em AzureBlob::list(), no
    | comando `azure:list` sem --connection, e como base do driver de Storage.
    |
    */

    'default' => env('AZURE_BLOB_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Conexões
    |--------------------------------------------------------------------------
    |
    | Cada conexão aponta para uma conta e um container. Há três modos de
    | autenticação, resolvidos nesta ordem de precedência:
    |
    |   1. sas_url (recomendado)
    |      URL completa com SAS token no nível de container. A própria URL já
    |      carrega o endpoint da conta, o container e o token, dispensando
    |      nome e chave. Nesse modo "container" é opcional e, quando definido,
    |      sobrescreve o que veio na URL.
    |      Ex.: https://conta.blob.core.windows.net/meu-container?sp=racwdl&sr=c&sig=...
    |
    |   2. connection_string
    |      Nome, chave e endpoint são extraídos da string. Exige "container".
    |
    |   3. name + key
    |      Conta e chave da conta. Exige "container".
    |
    | Só os modos 2 e 3 conseguem assinar SAS novos: com sas_url o pacote
    | reaproveita o token do container, e a expiração é a dele.
    |
    | Para várias contas, declare quantas conexões precisar e selecione com
    | AzureBlob::connection('nome') — é o equivalente ao AZURE_PREFIX da ferramenta
    | MCP que este pacote substitui.
    |
    */

    'connections' => [

        'default' => [

            'sas_url' => env('AZURE_STORAGE_SAS_URL'),

            'connection_string' => env('AZURE_STORAGE_CONNECTION_STRING'),

            'name' => env('AZURE_STORAGE_NAME'),

            'key' => env('AZURE_STORAGE_KEY'),

            'container' => env('AZURE_STORAGE_CONTAINER'),

            // Endpoint da conta. Derivado automaticamente na maioria dos casos;
            // preencha para domínio próprio ou para o Azurite.
            'url' => env('AZURE_STORAGE_URL'),

            'endpoint_suffix' => env('AZURE_STORAGE_ENDPOINT_SUFFIX', 'core.windows.net'),

            // Trava local de escrita: bloqueia upload, delete, copy e move antes
            // de a requisição sair. Não substitui as permissões do SAS.
            'readonly' => env('AZURE_READONLY', false),

            // Teto para download() em memória (5 MB). Acima disso o pacote
            // lança BlobTooLargeException — use stream() ou downloadTo().
            'max_download_size' => (int) env('AZURE_MAX_DOWNLOAD_SIZE', 5242880),

            // Tamanho de bloco no upload. Conteúdo maior que isso é enviado em
            // partes (Put Block + Put Block List) em vez de um PUT único.
            'block_size' => (int) env('AZURE_BLOCK_SIZE', 4194304),

            // Versão da REST API enviada em x-ms-version e usada para assinar
            // SAS. Mude apenas se precisar de um recurso mais novo.
            'api_version' => env('AZURE_API_VERSION', '2022-11-02'),

        ],

        /*
        | Exemplo de segunda conta. Cada conexão aceita as mesmas chaves acima e
        | pode sobrescrever "http" e "logging" individualmente.
        |
        | 'apostilas' => [
        |     'sas_url'   => env('APOSTILAS_AZURE_STORAGE_SAS_URL'),
        |     'container' => env('APOSTILAS_AZURE_STORAGE_CONTAINER'),
        |     'readonly'  => true,
        | ],
        */

    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | Valem para todas as conexões, e cada uma pode sobrescrever com sua própria
    | chave "http".
    |
    | Proxy: defina AZURE_PROXY para aplicar o mesmo proxy a http e https, ou use
    | as chaves específicas quando precisar separá-los. AZURE_PROXY_NO aceita uma
    | lista separada por vírgula de hosts que devem ignorar o proxy.
    |
    | Formato aceito: [protocolo://][usuario:senha@]host:porta
    |
    */

    'http' => [

        'proxy' => [
            'http' => env('AZURE_PROXY_HTTP', env('AZURE_PROXY')),
            'https' => env('AZURE_PROXY_HTTPS', env('AZURE_PROXY')),
            'no' => env('AZURE_PROXY_NO'),
        ],

        // Segundos de espera pela resposta completa. Uploads grandes em blocos
        // usam este timeout por bloco, não pelo arquivo inteiro.
        'timeout' => (int) env('AZURE_TIMEOUT', 60),

        // Segundos de espera para estabelecer a conexão TCP.
        'connect_timeout' => (int) env('AZURE_CONNECT_TIMEOUT', 10),

        // Verificação do certificado TLS. Mantenha true fora de depuração local.
        'verify' => env('AZURE_VERIFY_SSL', true),

    ],

    /*
    |--------------------------------------------------------------------------
    | Log
    |--------------------------------------------------------------------------
    |
    | O pacote registra o canal abaixo em logging.channels automaticamente, a
    | menos que a aplicação já tenha definido um canal com o mesmo nome — nesse
    | caso o da aplicação prevalece e "channel_config" é ignorado.
    |
    | SAS tokens, chaves e assinaturas são mascarados antes de qualquer escrita.
    |
    | Para mandar os logs para o canal padrão da aplicação, defina
    | AZURE_LOG_CHANNEL=null. Para silenciá-los, AZURE_LOG_ENABLED=false.
    |
    */

    'logging' => [

        'enabled' => env('AZURE_LOG_ENABLED', true),

        'channel' => env('AZURE_LOG_CHANNEL', 'azure-blob'),

        'channel_config' => [
            'driver' => 'daily',
            'path' => storage_path('logs/azure-blob.log'),
            'level' => env('AZURE_LOG_LEVEL', 'debug'),
            'days' => (int) env('AZURE_LOG_DAYS', 14),
            'replace_placeholders' => true,
        ],

    ],

];
