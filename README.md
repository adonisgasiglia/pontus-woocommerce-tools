# Pontus WooCommerce Tools

Plugin WordPress que centraliza as personalizações do fluxo de contratação da Pontus Escritórios Inteligentes no WooCommerce.

## Recursos atuais

- carregamento seguro pelo WordPress;
- verificação da dependência do WooCommerce;
- aviso de ativação no painel;
- cupons restritos aos adicionais do YITH;
- desconto percentual, fixo ou gratuito;
- suporte a Atendimento Telefônico e Pacote Mais Reuniões;
- proteção do valor-base do produto contra o desconto desses cupons;
- gancho `pwt_loaded` para os próximos módulos.

## Requisitos

- WordPress;
- WooCommerce ativo;
- YITH WooCommerce Product Add-ons & Extra Options;
- PHP 7.4 ou superior.

## Atualizações pelo WordPress

A partir da versão 1.1.1, o plugin pode ser atualizado pelo painel do WordPress com o Git Updater.

Como o repositório é privado, configure no Git Updater um token fine-grained do GitHub limitado a este repositório, com as permissões **Contents: Read-only** e **Metadata: Read-only**.

O plugin usa a branch `main` como origem das versões estáveis. Quando a versão do cabeçalho remoto for superior à instalada, o WordPress exibirá a atualização disponível.

## Instalação

1. Baixe o repositório como arquivo ZIP.
2. Confirme que a pasta compactada se chama `pontus-woocommerce-tools`.
3. No WordPress, acesse **Plugins > Adicionar plugin > Enviar plugin**.
4. Envie o ZIP e ative o plugin.

## Como criar um cupom para adicionais

1. Acesse **Marketing > Cupons** no WooCommerce.
2. Crie ou edite um cupom.
3. Em **Dados do cupom**, mantenha o tipo de desconto nativo desejado. O plugin zerará o desconto nativo quando o modo Pontus estiver ativo.
4. Marque **Desconto em adicionais Pontus**.
5. Escolha a modalidade:
   - **Percentual**: aplica a porcentagem informada ao total dos adicionais elegíveis;
   - **Valor fixo**: desconta o valor informado, limitado ao total dos adicionais;
   - **Gratuito**: desconta integralmente os adicionais elegíveis.
6. Marque **Atendimento Telefônico**, **Pacote Mais Reuniões** ou ambos.
7. Configure normalmente validade, limite de usos e demais restrições nativas do WooCommerce.
8. Salve o cupom.

O cupom somente será aceito quando o carrinho contiver pelo menos um adicional marcado como elegível.

## Cálculo

O plugin lê as opções selecionadas em `yith_wapo_options` e utiliza o preço informado pelo YITH. Os valores atuais de R$ 50 para Atendimento Telefônico e R$ 350 para Pacote Mais Reuniões são usados apenas como fallback quando o YITH não fornece o preço no item do carrinho.

Cupons Pontus aplicados em sequência nunca podem descontar mais que o total dos adicionais elegíveis.

## Próximas etapas

- validar a estrutura real de `yith_wapo_options` no ambiente de produção;
- tratar os dados de pessoa física e jurídica;
- padronizar os metadados do pedido;
- preparar os eventos consumidos pelo n8n, D4Sign e Conexa.
