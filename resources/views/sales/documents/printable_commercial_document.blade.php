<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <title>{{ $series }}-{{ $number }}.pdf</title>
    <style>
        @page { size: {{ $format === 'a4' ? 'A4 portrait' : '80mm auto' }}; margin: {{ $format === 'a4' ? '8mm' : '0' }}; }
        {!! file_get_contents(resource_path('views/sales/documents/partials/printable_commercial_document.css')) !!}
    </style>
</head>
<body class="{{ $format === 'a4' ? 'format-a4' : 'format-ticket' }}">
<div class="sheet">
    @php
        $showProductCodes = filter_var($showProductCodes ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $showProductCodes = $showProductCodes ?? false;

        $showVehicleInfo = filter_var($showVehicleInfo ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $showVehicleInfo = $showVehicleInfo ?? false;

        $showPaymentBrandIcons = filter_var($showPaymentBrandIcons ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $showPaymentBrandIcons = $showPaymentBrandIcons ?? false;

        $paymentBrandIcons = is_array($paymentBrandIcons ?? null) ? $paymentBrandIcons : [];
        $paymentBrandIcons = array_values(array_filter($paymentBrandIcons, static function ($icon) {
            if (!is_array($icon)) {
                return false;
            }

            $src = trim((string) ($icon['src'] ?? ''));
            return $src !== '';
        }));
    @endphp
    @if ($format === 'a4')
        <div class="header--a4">
            <div class="logo-col">
                @if (!empty($companyLogo))
                    <img src="{{ $companyLogo }}" alt="Logo" class="header-logo" />
                @endif
            </div>
            <div class="brand-col">
                <div class="brand-name">{{ $companyName }}</div>
                @if (!empty($companyDescription))<div class="brand-meta">{{ $companyDescription }}</div>@endif
                @if (!empty($companyPhone))<div class="brand-meta">Central telefonica: {{ $companyPhone }}</div>@endif
                @if (!empty($companyEmail))<div class="brand-meta">{{ $companyEmail }}</div>@endif
            </div>
            <div class="voucher-box">
                <div class="voucher-ruc">R.U.C. {{ $companyTaxId !== '' ? $companyTaxId : '-' }}</div>
                <div class="voucher-type">{{ $documentKindLabel }}</div>
                <div class="voucher-number">{{ $series }}-{{ $number }}</div>
            </div>
        </div>

        <div class="legend">Ano del Bicentenario, de la consolidacion de nuestra Independencia, y de la conmemoracion de las heroicas batallas de Junin y Ayacucho</div>

        <section class="info-box">
            <table class="info-grid">
                <tr>
                    <td>
                        <div class="line"><span class="k">R.U.C:</span><span class="v">{{ $customerDoc ?: '-' }}</span></div>
                        <div class="line"><span class="k">SENOR(ES):</span><span class="v">{{ $customer }}</span></div>
                        @if ($showVehicleInfo)<div class="line"><span class="k">TELEFONO:</span><span class="v">{{ $customerPhone ?: ($companyPhone ?: '-') }}</span></div>@endif
                        <div class="line"><span class="k">DIRECCION:</span><span class="v">{{ $customerAddress ?: '-' }}</span></div>
                        @if ($showVehicleInfo && !empty($vehicleInfo))<div class="line"><span class="k">VEHICULO:</span><span class="v">{{ $vehicleInfo }}</span></div>@endif
                    </td>
                    <td>
                        <div class="line"><span class="k">FECHA EMISION:</span><span class="v">{{ $issueDateOnly ?: $issueDate }}</span></div>
                        <div class="line"><span class="k">FECHA VENCIMIENTO:</span><span class="v">{{ $dueDate ?: ($issueDateOnly ?: '-') }}</span></div>
                        <div class="line"><span class="k">TIPO DE MONEDA:</span><span class="v">{{ strtoupper(($currencyCode ?? 'PEN') === 'PEN' ? 'SOLES' : ($currencyCode ?? '')) }}</span></div>
                        <div class="line"><span class="k">CODIGO DE PAIS:</span><span class="v">PER</span></div>
                    </td>
                </tr>
            </table>
        </section>

        <section class="info-box">
            <table class="info-grid">
                <tr>
                    <td>
                        <div class="line"><span class="k">NRO GUIA:</span><span class="v">{{ $guideNo ?: '-' }}</span></div>
                        @if (!empty($isNoteDocument))
                            <div class="line"><span class="k">DOC. AFECTADO:</span><span class="v">{{ trim(($sourceDocumentLabel ?? '-') . ' ' . ($sourceDocumentNumber ?? '-')) }}</span></div>
                            <div class="line"><span class="k">TIPO DE NOTA:</span><span class="v">{{ trim(($noteReasonCode ?? '-') . (!empty($noteReasonDescription) ? (' - ' . $noteReasonDescription) : '')) }}</span></div>
                        @endif
                        <div class="line"><span class="k">VENDEDOR:</span><span class="v"></span></div>
                        @foreach (($paymentBreakdown ?? []) as $paymentRow)
                            <div class="line"><span class="k">PAGO {{ $paymentRow['method'] }}:</span><span class="v">{{ $currency }} {{ $paymentRow['amount'] }}</span></div>
                        @endforeach
                    </td>
                    <td>
                        <div class="line"><span class="k">ORDEN COMPRA:</span><span class="v"></span></div>
                        <div class="line"><span class="k">COD. CLIENTE:</span><span class="v"></span></div>
                        <div class="line"><span class="k">INCOTERM:</span><span class="v"></span></div>
                        <div class="line"><span class="k">AREA.VTA:</span><span class="v"></span></div>
                    </td>
                </tr>
            </table>
        </section>

        <table class="items">
            <thead>
                <tr>
                    <th style="width:5%">Item</th>
                    @if ($showProductCodes)<th style="width:12%">Codigo</th>@endif
                    <th style="width:8%">Cant.</th>
                    <th style="width:8%">Unid.</th>
                    <th style="width:{{ $showProductCodes ? '33%' : '45%' }}">Descripcion</th>
                    <th style="width:10%">Valor Unit.</th>
                    <th style="width:10%">Precio Vta.</th>
                    <th style="width:7%">Dscto.</th>
                    <th style="width:7%">Valor Vta.</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="c">{{ $row['line_no'] > 0 ? $row['line_no'] : '-' }}</td>
                    @if ($showProductCodes)<td class="c">{{ $row['product_code'] !== '' ? $row['product_code'] : '-' }}</td>@endif
                    <td class="r">{{ $row['qty'] }}</td>
                    <td class="c">{{ $row['unit_label'] }}</td>
                    <td class="l">{{ $row['description'] }}</td>
                    <td class="r">{{ $row['unit_price'] }}</td>
                    <td class="r">{{ $row['unit_price'] }}</td>
                    <td class="r">0.00</td>
                    <td class="r">{{ $row['total'] }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ $showProductCodes ? '9' : '8' }}" class="c">SIN ITEMS</td></tr>
            @endforelse
            </tbody>
        </table>

        <section class="totals-wrap">
            <div class="words"><strong>SON:</strong> {{ $totalWords }}</div>
            <div class="totals">
                <table>
                    <tr><td class="k2">OP. GRAVADAS</td><td class="v2">{{ $currency }} {{ $gravadaTotal ?? $subtotal }}</td></tr>
                    <tr><td class="k2">OP. INAFECTAS</td><td class="v2">{{ $currency }} {{ $inafectaTotal ?? '0.00' }}</td></tr>
                    <tr><td class="k2">OP. EXONERADAS</td><td class="v2">{{ $currency }} {{ $exoneradaTotal ?? '0.00' }}</td></tr>
                    <tr><td class="k2">IGV</td><td class="v2">{{ $currency }} {{ $taxTotal }}</td></tr>
                    <tr class="grand"><td class="k2">TOTAL</td><td class="v2">{{ $currency }} {{ $grandTotal }}</td></tr>
                </table>
            </div>
        </section>
    @else
        <div class="ticket-header">
            @if (!empty($companyLogo))
                <div class="ticket-logo-wrap">
                    <img src="{{ $companyLogo }}" alt="Logo" class="header-logo" />
                </div>
            @endif
            <div class="ticket-company-name">{{ $companyName }}</div>
            @if ($companyTaxId !== '')<div class="ticket-company-meta">RUC: {{ $companyTaxId }}</div>@endif
            @if (!empty($companyAddress))<div class="ticket-company-meta">{{ strtoupper($companyAddress) }}</div>@endif
            @if (!empty($companyPhone))<div class="ticket-company-meta">TEL: {{ $companyPhone }}</div>@endif
            @if (!empty($companyEmail))<div class="ticket-company-meta">EMAIL: {{ $companyEmail }}</div>@endif
            <div class="ticket-doc-title">{{ $documentKindLabel }}</div>
            <div class="ticket-docno">{{ $series }}-{{ $number }}</div>
            @if (!empty($issueDateOnly))<div class="ticket-date">{{ $issueDateOnly }}</div>@endif
        </div>

        <div class="ticket-divider"></div>

        <div class="ticket-row"><div class="ticket-label">CLIENTE:</div><div class="ticket-value">{{ $customer }}</div></div>
        <div class="ticket-row"><div class="ticket-label">DOC.:</div><div class="ticket-value">{{ $customerDoc ?: '-' }}</div></div>
        <div class="ticket-row"><div class="ticket-label">NRO GUIA:</div><div class="ticket-value">{{ $guideNo ?: '-' }}</div></div>
        @if (!empty($isNoteDocument))
            <div class="ticket-row"><div class="ticket-label">DOC. AFECTADO:</div><div class="ticket-value">{{ trim(($sourceDocumentLabel ?? '-') . ' ' . ($sourceDocumentNumber ?? '-')) }}</div></div>
            <div class="ticket-row"><div class="ticket-label">TIPO DE NOTA:</div><div class="ticket-value">{{ trim(($noteReasonCode ?? '-') . (!empty($noteReasonDescription) ? (' - ' . $noteReasonDescription) : '')) }}</div></div>
        @endif
        <div class="ticket-row"><div class="ticket-label">DIRECCION:</div><div class="ticket-value">{{ $customerAddress ?: '-' }}</div></div>
        @if ($showVehicleInfo)<div class="ticket-row"><div class="ticket-label">TEL.:</div><div class="ticket-value">{{ $customerPhone ?: '-' }}</div></div>@endif
        @if ($showVehicleInfo && !empty($vehicleInfo))<div class="ticket-row"><div class="ticket-label">VEHICULO:</div><div class="ticket-value">{{ $vehicleInfo }}</div></div>@endif

        <div class="ticket-divider"></div>

        <table class="ticket-items">
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>
                        <div class="ticket-item-desc">
                            @if ($showProductCodes && $row['product_code'] !== '')<div class="ticket-item-code">COD: {{ $row['product_code'] }}</div>@endif
                            {{ strtoupper($row['description']) }}
                        </div>
                        <div class="ticket-item-price">
                            <span>{{ $row['qty'] }} x {{ $currency }} {{ $row['unit_price'] }}</span>
                            <span>{{ $currency }} {{ $row['total'] }}</span>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td>SIN ITEMS</td></tr>
            @endforelse
            </tbody>
        </table>

        <div class="ticket-summary">
            <div class="ticket-summary-row"><span>OP. GRAVADAS</span><span>{{ $currency }} {{ $gravadaTotal ?? $subtotal }}</span></div>
            <div class="ticket-summary-row"><span>OP. INAFECTAS</span><span>{{ $currency }} {{ $inafectaTotal ?? '0.00' }}</span></div>
            <div class="ticket-summary-row"><span>OP. EXONERADAS</span><span>{{ $currency }} {{ $exoneradaTotal ?? '0.00' }}</span></div>
            <div class="ticket-summary-row"><span>IGV</span><span>{{ $currency }} {{ $taxTotal }}</span></div>
            <div class="ticket-total"><span>TOTAL</span><span>{{ $currency }} {{ $grandTotal }}</span></div>
            <div class="ticket-words"><strong>SON:</strong> {{ $totalWords }}</div>
            @foreach (($paymentBreakdown ?? []) as $paymentRow)
                <div class="ticket-summary-row"><span>Pago {{ $paymentRow['method'] }}</span><span>{{ $currency }} {{ $paymentRow['amount'] }}</span></div>
            @endforeach
        </div>
    @endif

    <div class="footer">
        <div style="margin-bottom:6px;"><strong>Observaciones:</strong> {{ !empty($documentNotes) ? $documentNotes : 'Sin observaciones registradas.' }}</div>
        @if (!empty($electronicSignature))
            <div style="margin-bottom:4px; word-break:break-all;"><strong>Firma electronica:</strong> {{ $electronicSignature }}</div>
        @endif
        @if (!empty($companyBankAccounts) && is_array($companyBankAccounts))
            <div class="bank-title">BANCOS</div>
            @foreach ($companyBankAccounts as $bank)
                <div class="bank-item">
                    @if (!empty($bank['bank_name']))<div><strong>{{ $bank['bank_name'] }}</strong></div>@endif
                    @if (!empty($bank['account_number']))<div>Cuenta: {{ $bank['account_number'] }}</div>@endif
                    @if (!empty($bank['cci']))<div>CCI: {{ $bank['cci'] }}</div>@endif
                    @if (!empty($bank['account_holder']))<div>Titular: {{ $bank['account_holder'] }}</div>@endif
                </div>
            @endforeach
        @endif
        @if ($showPaymentBrandIcons && !empty($paymentBrandIcons))
            <div class="pay-logos">
                @foreach ($paymentBrandIcons as $icon)
                    <span class="pay-logo-item">
                        <img src="{{ $icon['src'] }}" alt="{{ $icon['alt'] ?? 'Pago' }}" />
                    </span>
                @endforeach
            </div>
        @endif
    </div>
</div>
</body>
</html>
