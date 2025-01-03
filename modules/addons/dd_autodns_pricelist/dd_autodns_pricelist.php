<?php
if (!defined('WHMCS')) {
    die('Access denied.');
}

function dd_autodns_pricelist_config()
{

    return [
        'name'        => 'DD: AutoDNS Price List Module',
        'description' => 'Fetches and displays the AutoDNS XML price list in the WHMCS admin area.',
        'version'     => '1.0.0',
        'author'      => "<a href='https://www.datadachs.eu' target='_blank' rel='noopener'>Sven Barthel</a>",
        'fields'      => [
            'username' => [
                'FriendlyName' => 'Username',
                'Type'         => 'text',
                'Size'         => '50',
                'Description'  => 'Your AutoDNS username.',
            ],
            'password' => [
                'FriendlyName' => 'Password',
                'Type'         => 'password',
                'Size'         => '50',
                'Description'  => 'Your AutoDNS password.',
            ],
        ],
    ];
}

function dd_autodns_pricelist_output($vars)
{

    $username = $vars['username'];
    $password = $vars['password'];

    $url = 'https://api.autodns.com/v1/document/price_list.xml';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/xml',
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        echo '<div class="errorbox">Curl error: ' . curl_error($ch) . '</div>';
        curl_close($ch);
        return;
    }

    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $localIp     = curl_getinfo($ch, CURLINFO_LOCAL_IP);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if (strpos($response, '<?xml') !== false) {
        $xml        = simplexml_load_string($response);
        $categories = ['single' => [], 'double' => [], 'invalid' => []];

        foreach ($xml->prices->domain as $domain) {
            $domainLabel = (string) $domain['label'];
            $prices      = ['create' => '-', 'renew' => '-', 'transfer' => '-', 'restore' => '-'];
            $currency    = '-';
            $period      = '-';
            foreach ($domain->businessCase as $case) {
                $caseLabel          = (string) $case['label'];
                $price              = $case->price;
                $prices[$caseLabel] = (string) $price['amount'];
                $currency           = (string) $price['currency'];
                $period             = (string) $price['period'];
            }

            if (preg_match('/^xn--/', $domainLabel) || !preg_match('/^[a-z0-9.-]+$/i', $domainLabel)) {
                $categories['invalid'][] = [$domainLabel, $prices, $currency, $period];
            } else {
                $labelParts = explode('.', $domainLabel);
                $depth      = count($labelParts);
                if ($depth === 1) {
                    $categories['single'][] = [$domainLabel, $prices, $currency, $period];
                } else {
                    $categories['double'][] = [$domainLabel, $prices, $currency, $period];
                }
            }
        }

        echo '<h2 class="page-title">AutoDNS XML Price List</h2>';
        echo '<p>This page displays the AutoDNS XML price list for domains. It is designed for viewing purposes only.<br/>To import domains and pricing into WHMCS, please go to <strong>WHMCS - Utilities - Registrar TLD Sync</strong>.</p>';
        echo '<div class="row" style="margin-bottom: 15px;">';
        echo '<div class="col-md-3"><input type="number" id="marginInput" class="form-control" placeholder="Margin in %" oninput="applyMarginAndRound()"></div>';
        echo '<div class="col-md-3">';
        echo '<select id="roundingOption" class="form-control" onchange="applyMarginAndRound()">';
        echo '<option value="none">No rounding</option>';
        echo '<option value="1">Round to whole numbers</option>';
        echo '<option value="10">Round to tens</option>';
        echo '</select></div>';
        echo '</div>';

        echo '<input type="text" id="domainSearch" class="form-control" placeholder="Search for domain extensions..." onkeyup="filterDomains()" style="margin-bottom: 15px;">';
        echo '<ul class="nav nav-tabs admin-tabs">';
        echo '<li class="active"><a href="#single" data-toggle="tab">1-Level</a></li>';
        echo '<li><a href="#double" data-toggle="tab">2-Level</a></li>';
        echo '<li><a href="#invalid" data-toggle="tab">Invalid/IDN Domains</a></li>';
        echo '</ul>';

        echo '<div class="tab-content admin-tabs-content">';
        foreach ($categories as $category => $domains) {
            $activeClass = ($category === 'single') ? 'active' : '';
            echo '<div id="' . $category . '" class="tab-pane ' . $activeClass . '">';
            echo '<table class="table table-bordered table-striped domain-table">';
            echo '<thead><tr>';
            echo '<th onclick="sortTable(this, 0)">Domain <span style="cursor: pointer;">&#9650;&#9660;</span></th>';
            echo '<th>Create</th>';
            echo '<th>Renew</th>';
            echo '<th>Transfer</th>';
            echo '<th>Restore</th>';
            echo '<th>Currency</th>';
            echo '<th>Period</th>';
            echo '</tr></thead><tbody>';
            foreach ($domains as $domainData) {
                list($domainLabel, $prices, $currency, $period) = $domainData;
                echo '<tr>';
                echo '<td>' . htmlspecialchars($domainLabel) . '</td>';
                echo '<td data-ek-price="' . htmlspecialchars($prices['create']) . '">' . htmlspecialchars($prices['create']) . '</td>';
                echo '<td data-ek-price="' . htmlspecialchars($prices['renew']) . '">' . htmlspecialchars($prices['renew']) . '</td>';
                echo '<td data-ek-price="' . htmlspecialchars($prices['transfer']) . '">' . htmlspecialchars($prices['transfer']) . '</td>';
                echo '<td data-ek-price="' . htmlspecialchars($prices['restore']) . '">' . htmlspecialchars($prices['restore']) . '</td>';
                echo '<td>' . htmlspecialchars($currency) . '</td>';
                echo '<td>' . htmlspecialchars($period) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
        }
        echo '</div>';

        echo '<script>
            function filterDomains() {
                var input = document.getElementById("domainSearch");
                var filter = input.value.toLowerCase();
                var tables = document.querySelectorAll(".domain-table tbody");
                tables.forEach(function(tbody) {
                    var rows = tbody.getElementsByTagName("tr");
                    for (var i = 0; i < rows.length; i++) {
                        var domainCell = rows[i].getElementsByTagName("td")[0];
                        if (domainCell) {
                            var domainText = domainCell.textContent || domainCell.innerText;
                            if (domainText.toLowerCase().indexOf(filter) > -1) {
                                rows[i].style.display = "";
                            } else {
                                rows[i].style.display = "none";
                            }
                        }
                    }
                });
            }

            function applyMarginAndRound() {
                var margin = parseFloat(document.getElementById("marginInput").value) || 0;
                var rounding = document.getElementById("roundingOption").value;
                var tables = document.querySelectorAll(".domain-table tbody");
                tables.forEach(function(tbody) {
                    var rows = tbody.getElementsByTagName("tr");
                    for (var i = 0; i < rows.length; i++) {
                        var cells = rows[i].querySelectorAll("td[data-ek-price]");
                        cells.forEach(function(cell) {
                            var ekPrice = parseFloat(cell.getAttribute("data-ek-price"));
                            if (!isNaN(ekPrice)) {
                                var newPrice = ekPrice + (ekPrice * margin / 100);
                                if (rounding === "1") {
                                    newPrice = Math.ceil(newPrice);
                                } else if (rounding === "10") {
                                    newPrice = Math.ceil(newPrice / 10) * 10;
                                }
                                cell.textContent = newPrice.toFixed(2);
                            }
                        });
                    }
                });
            }

            function sortTable(header, columnIndex) {
                var table = header.closest("table");
                var rows = Array.from(table.querySelectorAll("tbody tr"));
                var isAscending = !header.classList.contains("asc");
                
                table.querySelectorAll("th").forEach(function(th) {
                    th.classList.remove("asc", "desc");
                });

                header.classList.add(isAscending ? "asc" : "desc");

                rows.sort(function (a, b) {
                    var cellA = a.getElementsByTagName("td")[columnIndex].textContent.trim();
                    var cellB = b.getElementsByTagName("td")[columnIndex].textContent.trim();

                    var valueA = cellA.toLowerCase();
                    var valueB = cellB.toLowerCase();

                    if (valueA < valueB) return isAscending ? -1 : 1;
                    if (valueA > valueB) return isAscending ? 1 : -1;
                    return 0;
                });

                var tbody = table.querySelector("tbody");
                rows.forEach(function (row) {
                    tbody.appendChild(row);
                });
            }
        </script>';
    } else {
        echo '<div class="errorbox">Error fetching the price list. HTTP Code: ' . $httpCode . '</div>';
        echo '<p>Debug information:</p>';
        echo '<ul>';
        echo '<li>Local IP address: ' . htmlspecialchars($localIp) . '</li>';
        echo '<li>Response Content-Type: ' . htmlspecialchars($contentType) . '</li>';
        echo '<li>Response content:</li><pre>' . htmlspecialchars($response) . '</pre>';
        echo '</ul>';
    }
}
