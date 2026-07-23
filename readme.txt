=== Pontus WooCommerce Tools ===
Contributors: adonisgasiglia
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.3.3
License: GPLv2 or later

Personalizações do fluxo de contratação da Pontus Escritórios Inteligentes no WooCommerce.

== Description ==

Centraliza regras específicas da Pontus para WooCommerce e YITH Product Add-ons.

Recursos atuais:

* cupons restritos aos adicionais da Pontus;
* desconto percentual, fixo ou gratuito;
* suporte a Atendimento Telefônico e Pacote Mais Reuniões;
* proteção do valor-base do Escritório Inteligente;
* webhooks assinados para jornada, abandono, pedidos e pagamentos;
* itens contratuais individualizados com valor original, desconto e valor final.

== Installation ==

1. Envie a pasta pontus-woocommerce-tools para wp-content/plugins ou instale o arquivo ZIP pelo painel.
2. Ative o plugin.
3. Mantenha WooCommerce e YITH Product Add-ons ativos.

== Changelog ==

= 1.3.3 =
* Usa os preços reais do produto e dos adicionais nos itens enviados pelos webhooks.
* Mantém os preços fixos somente como fallback quando o YITH não informa o valor do adicional.

= 1.3.2 =
* Inclui número, bairro e país nos endereços enviados pelos webhooks.
* Reconhece os campos padrão do checkout e os campos alternativos criados pelo Efí.

= 1.3.1 =
* Corrige o valor enviado aos gateways Pix e cartão da Efí quando o produto contém adicionais do YITH.
* Usa o subtotal bruto gravado no item do pedido sem alterar o carrinho, o pedido ou outros meios de pagamento.

= 1.3.0 =
* Adiciona configuração de webhooks no menu WooCommerce.
* Inclui referência recolhível dos eventos, cabeçalhos e payload no painel.
* Rastreia as etapas reais do checkout multi-step com um checkout_id persistente.
* Envia eventos imediatos de jornada, pedido criado e pagamento confirmado.
* Detecta abandono após o prazo configurado, inclusive na etapa de pagamento.
* Envia Escritório Inteligente, Atendimento Telefônico e Pacote Mais Reuniões como itens separados.
* Informa valor original, desconto individual e valor final de cada item.
* Assina entregas com HMAC SHA-256 e tenta novamente em falhas temporárias.

= 1.2.13 =
* Impede que o YITH selecione novamente um adicional após a primeira tentativa de desmarcação.
* Mantém o estado da seleção automática mesmo quando o campo é recriado no HTML.

= 1.2.12 =
* Pré-seleciona no personalizador os adicionais que recebem desconto da campanha.
* Executa a seleção automática apenas uma vez e permite que o cliente desmarque o serviço.

= 1.2.11 =
* Exibe o código dos cupons Pontus em caixa alta no resumo do carrinho e checkout.
* Mantém o código interno original para aplicação e remoção do cupom.

= 1.2.10 =
* Oculta a mensagem de sucesso apenas quando a campanha aplica o cupom automaticamente.
* Mantém inalteradas as mensagens dos cupons inseridos manualmente no checkout.

= 1.2.9 =
* Persiste o cupom promocional em cookie próprio durante a contratação.
* Cria uma ponte no navegador que recompõe o parâmetro da campanha ao chegar ao checkout.
* Independe do mecanismo de redirecionamento usado por Elementor ou YITH.

= 1.2.8 =
* Envia o formulário de contratação diretamente ao checkout com o cupom dinâmico na URL.
* Evita que redirecionamentos do Elementor ou YITH descartem o parâmetro da campanha.

= 1.2.7 =
* Inclui o código da campanha diretamente na URL de redirecionamento para o checkout.
* Garante que o checkout capture o cupom após a inclusão do produto no carrinho.

= 1.2.6 =
* Corrige a aplicação automática em carrinhos com o formato compacto de opções do YITH.
* Unifica a validação de elegibilidade usada por cupons manuais e links promocionais.

= 1.2.5 =
* Reaplica cupons de campanha após o carrinho carregar os dados do YITH.
* Aumenta discretamente o tamanho de /mês no shortcode.

= 1.2.4 =
* Adiciona /mês ao final do shortcode de preço.
* Padroniza o valor do shortcode no azul da Pontus.

= 1.2.3 =
* Adiciona o shortcode [pontus_preco_plano] para o total dinâmico.
* Usa o azul da Pontus nos preços promocionais.

= 1.2.2 =
* Usa o preço-base estável do adicional no total do Elementor.
* Adapta as cores promocionais aos estados marcado e desmarcado.

= 1.2.1 =
* Atualiza o total escolhido no widget de preço do Elementor.
* Melhora o contraste dos preços promocionais nos adicionais do YITH.

= 1.2.0 =
* Adiciona links promocionais com aplicação automática de cupom.
* Adiciona preço de oferta para o plano principal e os adicionais do YITH.
* Permite que cupons promocionais tenham o plano principal como alvo.

= 1.1.3 =
* Consolida o cupom e o desconto em uma única linha no resumo do pedido.
* Identifica no rótulo quais adicionais recebem o desconto.

= 1.1.2 =
* Corrige a leitura do formato compacto das opções do YITH no carrinho.

= 1.1.1 =
* Adiciona compatibilidade com o Git Updater para atualizações pelo painel do WordPress.

= 1.1.0 =
* Adiciona cupons aplicáveis somente aos adicionais do YITH.

= 1.0.0 =
* Cria a estrutura inicial do plugin.
