## 0.2.0-rc4 - 2026-07-11

- Added a minimal ACP page under Posting > Excel Topics > XLSX support.
- Added a diagnostic for global attachments, the xlsx extension and its assigned group.
- Added an explicit one-click action that creates/enables a dedicated Excel Topics attachment group and assigns xlsx to it.
- The action keeps private-message attachments disabled and does not alter forum or user permissions.
- Added direct links to the native phpBB attachment, extension and extension-group pages.

## 0.2.0-rc3

- Mantém a Tabela estruturada como área principal.
- Recupera células informativas acima da Tabela, dentro das mesmas colunas.
- Exibe títulos, subtítulos e pares de rótulo/valor em um bloco compacto.
- Preserva linhas e colunas vazias que façam parte do intervalo estruturado.
- Mantém o comportamento anterior para arquivos sem Tabela estruturada.

# Changelog

## 0.2.0-rc2 — 2026-07-11

- Adicionada detecção da primeira Tabela estruturada do Excel na aba selecionada.
- Quando uma Tabela estruturada existe, somente o intervalo definido por ela é renderizado, excluindo títulos, observações e colunas vazias fora da tabela.
- Arquivos sem Tabela estruturada mantêm o comportamento anterior de usar a primeira linha não vazia como cabeçalho.
- Tabelas estruturadas sem linha de cabeçalho usam os nomes das colunas gravados no próprio XLSX.
- Mantidos os limites de 1.000 linhas e 50 colunas sobre o intervalo efetivamente exibido.
- Atualizada a versão do cache para descartar resultados antigos gerados pela leitura da planilha inteira.
- Adicionado arquivo de teste com conteúdo fora da tabela e validações do recorte estruturado.

## 0.2.0-rc1 — 2026-07-11

- XLSX armazenados em `files/exceltopics/` agora forçam o modo de streaming autenticado do phpBB, mesmo se o grupo de extensões estiver configurado como `PHYSICAL_LINK`.
- Downloads continuam passando por `download/file.php`, preservando as verificações de permissão e evitando redirecionamento direto para a pasta protegida.
- Falhas de armazenamento passam a ser registradas como eventos do sistema, sem atribuir usuário ou IP ao visitante que apenas acionou a operação.
- Adicionados testes para download seguro e autoria neutra dos logs de armazenamento.

## 0.2.0-beta2 — 2026-07-11

- Corrigida a exclusão de XLSX armazenados em `files/exceltopics/` sem depender do `basename()` usado pela rotina nativa.
- Adicionado suporte à exclusão de anexos por anexo, post, tópico e usuário por meio dos eventos oficiais do phpBB.
- Arquivos físicos compartilhados por tópicos copiados são preservados até a remoção da última referência.
- Registros físicos duplicados são agrupados para ajustar `upload_dir_size` e `num_files` exatamente uma vez.
- Caminhos gerenciados malformados são bloqueados antes da exclusão nativa e registrados no log.
- Adicionada restauração em lotes dos XLSX para a raiz de `files/` antes de desativar a extensão.
- Adicionada trava temporária para impedir que visualizações concorrentes movam arquivos restaurados de volta entre os lotes.
- A desativação é interrompida com erro quando a restauração não pode ser concluída com segurança.
- **Excluir dados** também restaura arquivos gerenciados, inclusive após uma desativação feita por versão anterior.
- Adicionada verificação de integridade em lotes durante a ativação.
- Adicionada reparação conservadora de movimentações interrompidas quando existe uma cópia utilizável na raiz.
- Adicionada limpeza conservadora de órfãos com prazo de sete dias e padrão explícito `et_<id>_`.
- Arquivos desconhecidos, manuais e nomes legados da beta1 não são removidos pela limpeza automática.
- Novos arquivos gerenciados usam prefixo interno explícito para permitir identificação segura de órfãos.
- O aviso móvel de rolagem deixou de ficar fixo em português no CSS e agora usa o sistema de idiomas.
- Ampliados os testes de exclusão, referências compartilhadas, contadores, restauração, colisões, integridade, órfãos e ciclo de ativação/desativação.

## 0.2.0-beta1 — 2026-07-11

- XLSX anexados ao primeiro post passam a ser movidos para `files/exceltopics/`.
- Adicionado suporte ao download de anexos XLSX armazenados no subdiretório da extensão.
- Adicionada criação automática de `files/exceltopics/index.htm` e `.htaccess` de proteção.
- A aba exibida agora é a aba ativa salva no XLSX, com fallback para a primeira aba visível.
- Adicionados metadados visíveis: aba, data de atualização, linhas e colunas.
- Linhas marcadas como ocultas no Excel deixam de ser exibidas.
- Logs passam a incluir tópico, post, anexo e autor do anexo.
- Falhas esperadas de planilha inválida são registradas no log administrativo; falhas inesperadas continuam críticas.
- Pequenas melhorias de CSS, impressão, linhas alternadas e rolagem em telas pequenas.
- Ampliados os testes de listener, armazenamento, download, metadados e logs.

## 0.1.0-beta1 — 2026-07-10

- Vendor, pacote, namespaces e serviços alterados de `forumtools` para `mundophpbb`.
- Mantido o mesmo fluxo funcional da alpha.
- Adicionado log crítico do phpBB para falhas de leitura e renderização.
- Adicionado cache de erros por 5 minutos para evitar reprocessamento repetido.
- Falhas do backend de cache deixam de interromper a renderização da tabela.
- Dados variáveis do log administrativo são escapados antes da interpolação.
- Adicionados limites de 4.096 caracteres por célula, 2 MiB de conteúdo de células e 4 MiB de HTML.
- Adicionados limites para strings compartilhadas, estilos e linhas XML excessivas.
- Adicionadas mensagens específicas para planilhas estruturalmente excessivas.
- Ampliados os testes de cache, UTF-8, log, limite de saída e leitura de XLSX real.

## 0.1.0-alpha1 — 2026-07-10

- Primeiro pacote instalável para homologação.
- Exibição automática do XLSX mais recente anexado ao primeiro post.
- Primeira aba visível e primeira linha não vazia como cabeçalho.
- Leitor OOXML mínimo, sem bibliotecas de terceiros.
- Cache, limites de segurança e tabela responsiva.
- Idiomas inglês e português brasileiro.
