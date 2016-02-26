<?php
namespace Boletos\Models\Boletos\Base;

use Boletos\Generators\Barcode;
use Boletos\Models\Bancos\Contracts\BancoInterface;
use Boletos\Models\Beneficiario\Contracts\BeneficiarioInterface;
use Boletos\Models\BoletoInfo\Contracts\BoletoInfoInterface;
use Boletos\Models\Boletos\Contracts\BoletoInterface;
use Boletos\Models\Pagador\Contracts\PagadorInterface;
use Carbon\Carbon;

abstract class Boleto implements BoletoInterface
{
    protected $banco;
    protected $beneficiario;
    protected $pagador;
    protected $info;

    public $processed =
        [
            /* --------[A]------- */
            'codigo_banco_compensacao'    => '',
            'linha_digitavel'             => '',
            /* --------[B]------- */
            'local_de_pagamento'          => "PREFERENCIALMENTE NAS CASAS LOT�RICAS AT� O VALOR LIMITE",
            'vencimento'                  => 'DD/MM/AAAA',
            /* --------[C]------- */

            'beneficiario'                =>
                [
                    'razao_social' => 'Raz�o Social ou Nome Fantasia do Benefici�rio',
                    'cpf_cnpj'     => 'CPF/CNPJ*',
                    'endereco'     => 'endereco',
                    'cidade'       => 'cidade'
                ],
            /*
             * Formato AAAA / XXXXXX-DV, onde:
             * AAAA: C�digo da Ag�ncia do Benefici�rio
             * XXXXXX: C�digo do Benefici�rio
             * DV: D�gito Verificador do C�digo do Benefici�rio (M�dulo 11), conforme Anexo VI
             */
            'agencia_codigo_beneficiario' => 'AAAA / XXXXXX-DV',
            /* --------[D]------- */

            'data_do_documento'           => 'DD/MM/AAAA',
            /*
             * Tamb�m chamado de �Seu N�mero�, � o n�mero utilizado
             * e controlado pelo Benefici�rio para identificar o t�tulo de cobran�a
             */
            'nr_do_documento'             => '',
            'especie_doc'                 => '',
            'aceite'                      => '',
            'data_do_processamento'       => 'DD/MM/AAAA',
            /*
             * - Formato: XYNNNNNNNNNNNNNNN-D, onde:
             *  X Modalidade/Carteira de Cobran�a (1-Registrada/2-Sem Registro)
             *  Y Emiss�o do boleto (4-Benefici�rio)
             *  NNNNNNNNNNNNNNN Nosso N�mero (15 posi��es livres do Benefici�rio)
             *  D *D�gito Verificador
             */
            'nosso_numero'                => 'XYNNNNNNNNNNNNNNN-D',
            /* --------[E]------- */
            'carteira'                    => 'SR ou RG',
            'especie_moeda'               => 'R$',
            'valor_documento'             => '< R$ 9.999.999,99',
            'uso_do_banco'                => NULL,//'n�o preencher',
            'qtde_moeda'                  => NULL,//'n�o preencher',
            'xValor'                      => NULL,//'n�o preencher',

            /* --------[F]------- */
            //             'instrucoes'               => 'Preenchido com array',
            'desconto'                    => NULL, //'n�o preencher',

            /* --------[G]------- */
            /*'juros'                       => NULL,'n�o preencher',*/

            /* --------[H]------- */
            /*'valor_cobrado'               => NULL,'n�o preencher',*/

            /* --------[I]------- */
            'pagador'                     =>
                [
                    'nome'              => NULL,
                    'endereco'          => NULL,
                    'cidade_estado_cep' => NULL,
                    'cpf_cnpj'          => NULL//'Obrigat�rio na Cobran�a Registrada.'
                ]
            ,
            'sacador'                     =>
                [
                    'nome'     => 'emitente original do documento que originou o boleto de cobran�a',
                    'cpf_cnpj' => ''
                ]
            ,
            /* --------[J]------- */

            'codigo_de_barras'            => ''
        ];

    private $atributos_parser = [
        ':taxa',
        ':multa',
        ':vencimento'
    ];

    public $demonstrativo_array = [];
    public $instrucoes_array    = [];
    public $bars                = [];

    /**
     * @param BancoInterface        $banco
     * @param BeneficiarioInterface $beneficiario
     * @param PagadorInterface      $pagador
     * @param BoletoInfoInterface   $info
     * @param Barcode               $barcodeGenerator
     */
    public function __construct(
        BancoInterface $banco,
        BeneficiarioInterface $beneficiario,
        PagadorInterface $pagador,
        BoletoInfoInterface $info,
        Barcode $barcodeGenerator
    )

    {
        $this->beneficiario = $beneficiario;
        $this->banco        = $banco;
        $this->info         = $info;
        $this->pagador      = $pagador;

        $this->processaDadosBoleto();

        $this->bars =
            $barcodeGenerator->getBarcode($this->getCodigoBarras(),$barcodeGenerator::TYPE_INTERLEAVED_2_5)['bars'];
    }

    public function processaDadosBoleto()
    {
        $this->processed['codigo_banco_compensacao'] = $this->banco->getCodigoCompensacao();
        $this->processed['linha_digitavel']          = $this->getLinhaDigitavelFormatada();
        $this->processed['beneficiario']             =
            [
                'razao_social' => $this->beneficiario->getRazaoSocial(),
                'agencia'      => $this->beneficiario->getAgencia(),
                'cpf_cnpj'     => $this->beneficiario->getCpfCnpj(),
                'endereco'     => $this->beneficiario->getEndereco(),
                'cidade'       => $this->beneficiario->getCidadeEstado()
            ];
        $this->processed['nosso_numero']             = $this->getNossoNumeroFormatado();
        $this->processed['nr_do_documento']          = $this->getNumeroDocumento();
        $this->processed['valor_documento']          = $this->getValorDocumento();
        $this->processed['especie_doc']              = $this->getEspecieDoc();
        $this->processed['carteira']                 = $this->getCarteiraBeneficiario();
        $this->processed['aceite']                   = $this->getAceite();

        $this->processed['vencimento']                  = $this->getDataVencimento();
        $this->processed['agencia_codigo_beneficiario'] = $this->getAgencia();
        $this->processed['data_do_documento']           = $this->getDataDocumento();
        $this->processed['data_do_processamento']       = $this->getDataProcessamento();
        $this->processed['carteira']                    = $this->getCarteiraBeneficiario();
        $this->processed['especie_moeda']               = $this->getEspecieMoeda();
        $this->processed['pagador']                     =
            [
                'nome'              => $this->getNomePagador(),
                'endereco'          => $this->getEnderecoPagador(),
                'cidade_estado_cep' => $this->getCidadeEstadoCepPagador(),
                'cpf_cnpj'          => $this->getCpfCnpjPagador(),
            ];
        $this->processed['sacador']                     =
            [
                'nome'     => $this->getNomeSacado(),
                'cpf_cnpj' => $this->getCpfCnpjSacado()
            ];

        $this->processed['codigo_de_barras'] = $this->getCodigoBarras();
    }

    private function getAtributosParser()
    {
        return $this->atributos_parser;
    }

    protected function parseAttributes($string)
    {
        foreach ($this->getAtributosParser() as $attribute)
        {
            if (strpos($string, $attribute) !== FALSE)
            {
                switch ($attribute)
                {
                    case ":taxa":
                        $string = preg_replace("/$attribute" . '\b/', $this->getValorTaxa(), $string);
                        break;
                    case ":multa":
                        $string = preg_replace("/$attribute" . '\b/', $this->getValorMulta(), $string);
                        break;
                    case ":vencimento":
                        $string = preg_replace("/$attribute" . '\b/', $this->info->getDataVencimentoCalculada(), $string);
                        break;
                }
            }
        }

        return $string;
    }




    private function getValorTaxa($valor_inteiro = FALSE)
    {
        $taxa       = $this->getTaxaPercentual() / 100;
        $valor_taxa = intval($taxa * $this->getValorCobrado());

        if ($valor_inteiro)
        {
            return $valor_taxa;
        } else
        {
            return $this->formataValor($valor_taxa);
        }
    }


    private function getValorMulta($valor_inteiro = FALSE)
    {
        $multa = $this->getMultaPencentual() / 100;

        $valor_multa = intval($multa * $this->getValorCobrado());

        if ($valor_inteiro)
        {
            return $valor_multa;
        } else
        {
            return $this->formataValor($valor_multa);
        }
    }

    public function adicionaDemonstrativo($string)
    {
        $this->demonstrativo_array[] = $this->parseAttributes($string);

        return $this;
    }

    public function adicionaInstrucao($string)
    {
        $this->instrucoes_array[] = $this->parseAttributes($string);

        return $this;
    }
}