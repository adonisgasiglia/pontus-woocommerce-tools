# Pontus WooCommerce Tools

Plugin WordPress que centraliza as personalizações do fluxo de contratação da Pontus Escritórios Inteligentes no WooCommerce.

## Estado atual

Esta primeira versão estabelece a base do plugin:

- carregamento seguro pelo WordPress;
- classe principal em namespace próprio;
- verificação da dependência do WooCommerce;
- aviso de ativação no painel;
- gancho `pwt_loaded` para os próximos módulos;
- proteção contra acesso direto aos arquivos PHP.

## Requisitos

- WordPress;
- WooCommerce ativo;
- PHP 7.4 ou superior.

## Instalação para teste

1. Baixe o repositório como arquivo ZIP.
2. Confirme que a pasta compactada se chama `pontus-woocommerce-tools`.
3. No WordPress, acesse **Plugins > Adicionar plugin > Enviar plugin**.
4. Envie o ZIP e ative o plugin.
5. Confirme o aviso verde de ativação no painel.

## Próximas etapas

- organizar os módulos de checkout;
- tratar os dados de pessoa física e jurídica;
- persistir os adicionais Atendimento Telefônico e Mais Reuniões;
- padronizar os metadados do pedido;
- preparar os eventos consumidos pelo n8n, D4Sign e Conexa.
