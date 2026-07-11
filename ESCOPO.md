# Escopo congelado — 0.2.0-rc4

## Incluído

1. Upload pelo mecanismo nativo de anexos do phpBB.
2. Somente o primeiro post do tópico.
3. Somente o XLSX mais recente desse post.
4. Armazenamento dos XLSX da extensão em `files/exceltopics/`.
5. Aba ativa salva no XLSX, com fallback para a primeira aba visível.
6. Primeira Tabela estruturada da aba, quando existir, com fallback para a primeira linha não vazia como cabeçalho.
7. Tabela HTML responsiva e somente leitura.
8. Data de atualização, aba, linhas e colunas visíveis na tabela.
9. Linhas ocultas do Excel ignoradas.
10. Atualização ao substituir o anexo.
11. Cache, português brasileiro e inglês.
12. Limites fixos de arquivo, XML, linhas, colunas, células, conteúdo e HTML.
13. Cache curto de erros e logs com tópico, post, anexo e autor do anexo.
14. Vendor e namespace `mundophpbb`.
15. Exclusão pelo caminho completo dos arquivos em `files/exceltopics/`.
16. Preservação de arquivos físicos ainda referenciados por anexos copiados.
17. Atualização única dos contadores nativos por arquivo físico removido.
18. Restauração em lotes para a raiz de `files/` antes de desativar ou excluir dados.
19. Bloqueio da desativação quando a restauração não puder ser concluída com segurança.
20. Trava temporária contra movimentação reversa durante a restauração em lotes.
21. Verificação de integridade em lotes ao ativar.
22. Limpeza conservadora somente de órfãos antigos com padrão explícito da beta2.
23. Proteção contra caminhos gerenciados malformados na exclusão nativa.
24. Streaming autenticado obrigatório para XLSX gerenciados, independentemente do modo físico do grupo.
25. Logs de armazenamento atribuídos ao sistema, sem usuário ou IP visitante.
26. Recorte automático da primeira Tabela estruturada do Excel na aba selecionada.

## Explicitamente fora

- upload paralelo ao sistema de anexos;
- painel ACP próprio;
- tabelas ou colunas novas no banco;
- leitura de pasta monitorada;
- formulário ou BBCode para escolha de aba;
- várias abas exibidas ao mesmo tempo;
- `.xls`, `.csv`, Google Sheets ou APIs externas;
- edição de células dentro do phpBB;
- recálculo de fórmulas;
- reprodução completa da formatação do Excel;
- gráficos, imagens, filtros, ordenação ou paginação;
- tarefas agendadas, sincronização ou colaboração;
- suporte ao phpBB 4.x.

## Trava contra crescimento

Qualquer solicitação que exija banco próprio, tela administrativa, serviço externo, tarefa em segundo plano, editor JavaScript ou alteração do núcleo fica para outro marco. Nada disso entra como “só mais uma coisa”.

## Critério de aceite

1. Atualizar a partir da `0.2.0-beta1` sem alterar tópicos manualmente.
2. Manter exibição e download dos XLSX já organizados em `files/exceltopics/`.
3. Criar um tópico e anexar um XLSX ao primeiro post.
4. Exibir a aba ativa salva no arquivo.
5. Usar fallback para a primeira aba visível quando necessário.
6. Exibir data, aba, linhas e colunas.
7. Ignorar linhas ocultas.
8. Editar o primeiro post, substituir o anexo e visualizar os dados atualizados.
9. Abrir o tópico em tela estreita sem quebrar o layout e mostrar o aviso no idioma do usuário.
10. Excluir apenas um XLSX e remover seu arquivo físico quando não houver outra referência.
11. Excluir um post, tópico ou conjunto de anexos de usuário sem deixar XLSX gerenciados referenciados.
12. Preservar um arquivo físico compartilhado enquanto pelo menos uma referência permanecer.
13. Ajustar espaço e quantidade somente uma vez ao remover a última referência.
14. Desativar a extensão e manter todos os downloads funcionando pelo phpBB nativo.
15. Interromper a desativação diante de arquivo ausente, colisão insegura ou falha de escrita.
16. Impedir que acessos concorrentes movam arquivos de volta durante uma desativação em lotes.
17. Reativar e reorganizar os XLSX gradualmente na primeira visualização dos tópicos.
18. Excluir dados da extensão sem deixar referências dependentes da subpasta.
19. Reparar uma movimentação interrompida quando existir uma cópia segura na raiz.
20. Preservar arquivos desconhecidos e remover apenas órfãos antigos identificáveis da beta2.
21. Impedir que um caminho gerenciado malformado seja reduzido a um arquivo homônimo da raiz.
22. Processar uma falha de leitura apenas uma vez durante o cache curto e registrá-la com contexto administrativo.
23. Impedir que textos, estruturas ou HTML excessivos comprometam a página.
24. Baixar XLSX gerenciados pelo `download/file.php` mesmo quando o grupo estiver em modo físico.
25. Registrar falhas de armazenamento sem atribuição ao visitante que acionou a operação.
26. Exibir somente o intervalo da primeira Tabela estruturada quando houver conteúdo decorativo fora dela.
27. Preservar o fallback de planilha inteira quando não existir Tabela estruturada.

- Cabeçalho informativo compacto com células preenchidas acima da Tabela estruturada, nas mesmas colunas.
- Linhas e colunas vazias dentro do intervalo da Tabela estruturada são preservadas.
