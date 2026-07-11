# Excel Topics para phpBB

Extensão pequena e somente leitura para exibir o arquivo `.xlsx` mais recente anexado ao primeiro post de um tópico como tabela HTML responsiva.

## Versão 0.2.0-rc4

Esta versão candidata a estável acrescenta uma correção de interpretação para planilhas reais: quando a aba selecionada contém uma **Tabela estruturada do Excel**, a extensão renderiza somente o intervalo definido por essa tabela. Títulos, observações e colunas decorativas fora do intervalo deixam de aparecer.

Arquivos sem Tabela estruturada continuam usando o comportamento anterior, sem exigir qualquer alteração do usuário.

Além disso, a RC2 mantém todo o ciclo de vida seguro dos arquivos implementado nas versões anteriores:

- exclui o arquivo físico correto ao remover um anexo, post, tópico ou usuário;
- preserva o arquivo enquanto outra cópia de tópico ainda referencia o mesmo arquivo físico;
- ajusta `upload_dir_size` e `num_files` uma única vez por arquivo efetivamente removido;
- restaura todos os XLSX para a raiz de `files/` antes de desativar a extensão;
- impede que visualizações concorrentes movam os arquivos de volta durante uma desativação em lotes;
- interrompe a desativação quando algum arquivo não puder ser restaurado, evitando links quebrados silenciosamente;
- restaura os arquivos também antes de **Excluir dados**, inclusive quando uma versão anterior já tiver sido desativada;
- verifica referências armazenadas ao ativar e tenta reparar cópias interrompidas de forma conservadora;
- remove somente órfãos antigos criados com o padrão explícito da beta2, sem apagar arquivos desconhecidos;
- traduz o aviso de rolagem horizontal conforme o idioma do usuário;
- protege caminhos malformados para que não sejam reduzidos a um nome-base e confundidos com arquivos da raiz;
- força XLSX gerenciados a passar pelo streaming autenticado do phpBB, mesmo quando o grupo estiver configurado como link físico;
- registra falhas de armazenamento como eventos do sistema, sem atribuí-las ao visitante que acionou a leitura.

O comportamento visível da tabela permanece igual ao da `0.2.0-beta1`.

## Escopo funcional

- usa o sistema de anexos nativo do phpBB;
- considera somente o primeiro post do tópico;
- usa somente o XLSX mais recente desse post;
- armazena os XLSX usados pela extensão em `files/exceltopics/`;
- exibe a aba ativa salva no arquivo, com fallback para a primeira aba visível;
- usa a primeira Tabela estruturada da aba quando ela existir;
- sem Tabela estruturada, usa a primeira linha não vazia como cabeçalho;
- atualiza a tabela quando o anexo é substituído;
- mantém o anexo normal disponível para download;
- devolve os arquivos à pasta nativa antes de desativar ou excluir os dados da extensão;
- escapa todo conteúdo de célula antes de gerar HTML.

## Requisitos

- phpBB 3.3.17;
- PHP 8.2 ou superior;
- módulos PHP `zip`, `xmlreader` e `simplexml`;
- anexos ativados no phpBB;
- extensão de arquivo `xlsx` permitida no ACP;
- estilo baseado em prosilver, preservando os eventos padrão do phpBB.

Esta versão não oferece suporte ao phpBB 4.x.

## Atualização da 0.2.0-rc1

1. Faça backup do banco de dados e da pasta `files/`.
2. Mantenha a extensão ativa.
3. Substitua os arquivos em `ext/mundophpbb/exceltopics/` pelos arquivos da `0.2.0-rc4`.
4. Limpe o cache do phpBB.
5. Abra um tópico com XLSX e teste a tabela e o download.
6. Opcionalmente, altere temporariamente o grupo `xlsx` para modo de link físico e confirme que o download continua passando pelo phpBB.

Não é necessário desativar, excluir dados ou mover anexos para esta atualização.

## Instalação nova

1. Extraia o pacote.
2. Copie a pasta `mundophpbb` para a pasta `ext` do phpBB.
3. Confirme o caminho `ext/mundophpbb/exceltopics/composer.json`.
4. No ACP, abra **Personalizar > Gerenciar extensões**.
5. Ative **Excel Topics**.
6. Em **Postagem > Gerenciar grupos de extensões**, confirme que `xlsx` pertence a um grupo autorizado e está permitido nos fóruns desejados.
7. Limpe o cache do phpBB.

## Uso

1. Abra o arquivo no Excel, LibreOffice ou Google Sheets.
2. Deixe selecionada a aba que deseja exibir.
3. Salve o arquivo `.xlsx`.
4. Crie um tópico normalmente.
5. No primeiro post, anexe o arquivo `.xlsx`. Não é necessário usar **Inserir na mensagem**.
6. Publique.

A tabela aparecerá no primeiro post. O arquivo continuará na caixa normal de anexos para download.

### Atualizar os dados

1. Edite o primeiro post.
2. Exclua o XLSX antigo.
3. Anexe a nova versão.
4. Salve o post.

Também é possível manter o anexo antigo e adicionar outro XLSX. Nesse caso, a extensão exibe o anexo XLSX com o maior identificador, normalmente o mais recente.

### Escolher outra aba

Não há formulário novo. Para trocar a aba exibida:

1. Abra o XLSX.
2. Clique na aba desejada.
3. Salve o arquivo.
4. Substitua o anexo no primeiro post.

A extensão lê a aba ativa salva no próprio arquivo.

## Armazenamento e ciclo de vida

Os arquivos processados ficam em:

```text
files/exceltopics/
```

A pasta recebe `index.htm` e `.htaccess` para reduzir acesso direto. O download sempre passa pelo mecanismo autenticado do phpBB e pelas permissões normais de anexos, inclusive se o grupo XLSX estiver configurado como link físico.

### Exclusão

Quando o phpBB exclui um anexo, post, tópico ou conjunto de anexos de um usuário, a extensão:

1. retira os caminhos gerenciados da rotina nativa que usa somente o nome-base;
2. aguarda a exclusão dos registros no banco;
3. verifica se ainda existe outra referência ao mesmo arquivo físico;
4. remove o arquivo pelo caminho completo somente quando a última referência desaparece;
5. devolve ao phpBB o espaço e a quantidade efetivamente removidos para atualização dos contadores.

### Desativação e exclusão de dados

Antes de ficar inativa, a extensão copia cada XLSX para a raiz configurada de anexos, atualiza todas as referências correspondentes no banco e só então remove a cópia da subpasta. Uma trava temporária de quinze minutos, renovada a cada lote, impede que visualizações simultâneas reorganizem os arquivos de volta durante esse processo.

Se faltar um arquivo, houver colisão sem solução segura ou a pasta não permitir escrita, a desativação é interrompida. A extensão permanece ativa e mostra o erro no ACP, preservando o acesso atual enquanto o problema é corrigido.

Depois de uma desativação concluída, os anexos continuam sendo baixados pelo phpBB sem depender da extensão.

### Integridade e órfãos

Na ativação, referências que ainda apontam para `files/exceltopics/` são verificadas em lotes. Uma cópia de raiz compatível pode reparar uma movimentação interrompida; divergências não solucionáveis são registradas no log.

A limpeza automática é deliberadamente restrita. Ela considera somente arquivos:

- dentro de `files/exceltopics/`;
- sem referência na tabela de anexos;
- com nome iniciado pelo padrão interno `et_<id>_` criado pela beta2;
- com pelo menos sete dias de idade.

Arquivos com nomes desconhecidos, arquivos manuais e nomes legados da beta1 são preservados.

## Comportamento dos dados

- quando a aba contém uma Tabela estruturada do Excel, somente o primeiro intervalo estruturado é exibido;
- títulos, observações e células fora desse intervalo são ignorados;
- sem Tabela estruturada, a primeira linha não vazia é o cabeçalho;
- linhas completamente vazias são ignoradas;
- linhas ocultas no Excel são ignoradas;
- fórmulas não são recalculadas; é usado o valor salvo no arquivo;
- datas, horas, percentuais e alguns formatos numéricos comuns recebem conversão básica;
- cores, bordas, fontes, larguras, células mescladas, imagens e gráficos não são reproduzidos;
- macros, links e HTML das células não são executados.

## Limites de segurança

| Item | Limite |
|---|---:|
| Arquivo XLSX compactado | 5 MiB |
| Parte XML individual | 20 MiB |
| Expansão total do ZIP | 60 MiB |
| Entradas no ZIP | 2.000 |
| Linhas não vazias exibidas | 1.000 |
| Colunas exibidas | 50 |
| Caracteres por célula | 4.096 |
| Conteúdo total de células | 2 MiB |
| HTML gerado | 4 MiB |
| Strings compartilhadas | 100.000 / 8 MiB |
| Estilos de célula | 10.000 |

Quando for seguro mostrar uma parte da tabela, a extensão reduz o conteúdo e informa o limite aplicado. Arquivos inválidos ou estruturalmente excessivos exibem uma mensagem de erro em vez de interromper a página.

## Cache e diagnóstico

Resultados válidos são armazenados em cache por até 24 horas, com uma chave baseada no identificador do anexo e no estado físico do arquivo. A substituição do anexo gera uma chave nova imediatamente.

Falhas são armazenadas por 5 minutos. Assim, um XLSX corrompido não é aberto novamente a cada visita. A primeira falha de cada período é registrada no log do phpBB contendo nome do arquivo, tópico, post, anexo, autor do anexo, código público e detalhe técnico.

Falhas de armazenamento também são registradas como eventos do sistema, incluindo a operação, o identificador do anexo quando disponível, o caminho físico controlado e o detalhe técnico. Elas não são atribuídas ao visitante nem ao IP que apenas acionou a operação.

## Testes locais

No diretório da extensão:

```sh
./tests/run.sh
```

O teste de integração com um XLSX real é executado quando ZipArchive, XMLReader e SimpleXML estão disponíveis; caso contrário, ele é marcado como ignorado.

## Estado

`0.2.0-rc4` é a versão candidata a estável, focada na leitura correta de Tabelas estruturadas do Excel e no fechamento do armazenamento. O escopo congelado está em [ESCOPO.md](ESCOPO.md).

## Cabeçalho informativo

Quando a aba contém uma Tabela estruturada, a extensão exibe a Tabela como área principal e preserva, em um bloco compacto, células preenchidas que estejam acima dela nas mesmas colunas. Linhas decorativas vazias são ignoradas.

## Atalho para habilitar XLSX

Depois de instalar/atualizar e executar as migrations, abra:

```text
ACP > Postagem > Excel Topics > Suporte XLSX
```

A tela verifica se anexos estão ativos, se `xlsx` está cadastrado e se o grupo correspondente está habilitado. O botão **Ativar suporte XLSX** cria ou habilita um grupo dedicado, atribui `xlsx`, configura download interno seguro e limite de 5 MiB. A ação não altera permissões de usuários/fóruns nem ativa anexos em mensagens privadas.

