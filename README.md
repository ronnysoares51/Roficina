# R Oficina

Projeto de gestão para oficinas de carros e motos.

## Fluxo da oficina

1. Cadastre o cliente e o veículo com tipo, placa, marca, modelo e quilometragem.
2. Registre o defeito relatado e o diagnóstico na ordem de serviço.
3. Monte o orçamento com quantidade, preço unitário e subtotal de cada peça, mão de obra e total.
4. Envie o orçamento ao WhatsApp do cliente pelo painel. Registre o aceite pelo provedor separadamente da confirmação de entrega.
5. Registre a decisão do cliente e acompanhe o serviço até a entrega do veículo.

## Referência avaliada

O [AutoKore](https://github.com/nathanfdasilva-prog/autokore/tree/a972a2287c37a05f4860e1f495504e7781233962) foi avaliado com orçamentos fictícios de um Fiat Uno e uma Honda CG 160. O botão atual abre uma conversa do WhatsApp com os totais. O envio pelo servidor via Evolution API foi testado com um provedor simulado e ainda precisa ser ligado ao fluxo do orçamento. Não foi localizada uma licença de reutilização para o AutoKore.

O [MapOS](https://github.com/RamonSilva20/mapos) é uma opção de base com licença Apache 2.0. Exige adaptação para cadastro de carros e motos e envio de orçamento pela API.

## Estado do projeto

Este repositório contém a descrição inicial do projeto. A aplicação e a publicação na Hostinger ficam para uma etapa posterior.
