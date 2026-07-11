# Testes locais

Execute:

```sh
./run.sh
```

Os testes rápidos validam:

- auxiliares do leitor e normalização segura de caminhos OOXML;
- formatação básica de datas, percentuais e números;
- seleção da aba ativa e fallback para a primeira aba visível;
- truncamento UTF-8 por quantidade de caracteres;
- geração segura e limitada do HTML;
- aviso móvel localizado pelo sistema de idiomas;
- cache de resultados e invalidação por alteração do arquivo;
- cache curto de falhas e apenas um registro no log administrativo/crítico;
- seleção de apenas um XLSX;
- movimentação de XLSX para `files/exceltopics/`;
- atualização conjunta de referências que compartilham o mesmo arquivo físico;
- restauração do subdiretório durante o download;
- resolução segura do caminho do anexo;
- exclusão somente após a última referência física;
- agrupamento de linhas duplicadas e atualização única dos contadores;
- bloqueio de caminhos malformados antes da exclusão nativa por nome-base;
- restauração em lotes antes de desativar ou excluir dados;
- trava temporária contra movimentação reversa entre lotes de restauração;
- preservação de colisões diferentes na raiz;
- bloqueio seguro da desativação quando um arquivo está ausente;
- reparação de cópia interrompida durante a verificação de integridade;
- limpeza somente de órfãos antigos com o padrão explícito da extensão;
- preservação de arquivos manuais ou desconhecidos;
- ciclo em lotes de ativação, desativação e purge;
- integração com a linha de template do primeiro post;
- metadados visíveis de aba, atualização e dimensão;
- leitura de um XLSX real incluído em `fixtures/basic.xlsx`;
- detecção e recorte de Tabela estruturada em `fixtures/structured_table.xlsx`;
- exclusão de títulos e observações localizados fora do intervalo estruturado;
- download de XLSX gerenciado forçado pelo streaming autenticado do phpBB;
- logs de armazenamento sem atribuição ao visitante.

O teste de integração é ignorado automaticamente quando ZipArchive, XMLReader ou SimpleXML não estão disponíveis no PHP da linha de comando. A extensão, porém, exige esses módulos para ser ativada no phpBB.
