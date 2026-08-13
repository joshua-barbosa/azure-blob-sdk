# Política de segurança

## Versões suportadas

| Versão | Suporte |
|---|---|
| 0.1.x | ✅ |

O pacote está em `0.x`: correções de segurança saem na série menor mais recente.

## Reportando uma vulnerabilidade

**Não abra issue pública para falha de segurança.**

Use o [Security Advisory privado do GitHub](https://github.com/joshua-barbosa/azure-blob-sdk/security/advisories/new),
que permite discutir e corrigir antes da divulgação.

Inclua, se possível: versão do pacote, versão do Laravel e do PHP, o que a falha
permite fazer e um caso mínimo que a reproduza.

Este pacote é mantido nas horas vagas, então não há SLA. Farei o possível para
responder em alguns dias.

## Se você vazou uma credencial

Se uma chave de conta ou SAS token apareceu num log, num commit ou numa issue:

1. **Rotacione a chave** no portal do Azure (Storage account → Access keys →
   Rotate). Isso invalida na hora todo SAS assinado com ela.
2. Um SAS token **não pode ser revogado individualmente** — só rotacionando a
   chave que o assinou, ou revogando a stored access policy, se ele usar uma.
3. Revise os logs de acesso da conta no período de exposição.

O pacote mascara chaves, SAS tokens e a assinatura (`sig=`) dentro de URLs antes
de escrever qualquer coisa no log — ver `AzureBlob\Support\Redactor`. Se você
encontrar um caminho em que uma credencial escapa dessa proteção, **isso é uma
vulnerabilidade** e vale o report privado.

## Escopo

Considero vulnerabilidade, entre outros:

- Credencial que escape do `Redactor` e chegue ao log.
- Falha na assinatura Shared Key ou na geração de SAS que permita forjar acesso.
- Injeção via nome de blob, prefixo ou parâmetro que altere a requisição
  assinada.

**Não** é vulnerabilidade do pacote:

- Container público configurado como público no Azure.
- SAS token com permissões amplas demais emitido pelo usuário.
- Conta com `allowSharedKeyAccess` habilitado — é a premissa deste pacote, que
  não suporta Azure AD / Managed Identity.
