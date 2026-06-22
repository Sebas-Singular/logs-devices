param(
    [string]$Endpoint = "http://localhost:8080/api/ingest.php",
    [string]$Secret = "change-me-dev",
    [string]$UserAgent = "WalkerPisa-Bridge-Logs"
)

$ErrorActionPreference = "Stop"

$script:Passed = 0
$script:Failed = 0

function New-TestBody {
    param(
        [string]$BridgeId = "120",
        [string]$LogText = "[2026-05-07 09:30:00] [TELEMETRY] INFO: [AA:BB:CC:DD:EE:FF] TELEMETRY -> id=01 name='Baliza Test' timestamp=2026-05-07 09:30:00 | T=22.5C H=40.00% P=935.9hPa AQ=50.0 (READY acc=1 stab=1 runin=1) alt=665m | LiDAR=5209mm | SOC=82% DISCHARGING (BAT) | RSSI=-67 | LANE=1 LOC=0 POS=-1 REF=7851 SX=103.5 SY=56.9",
        [string]$Message = "bridge_logs"
    )

    $uniqueSentAt = (Get-Date).ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ss.fffZ")
    $uniqueOffset = Get-Random -Minimum 100000 -Maximum 999999

    $body = @{
        message = $Message
        bridgeId = $BridgeId
        bridgeName = "WalkerPisa Bridge"
        sentAt = $uniqueSentAt
        fromOffset = $uniqueOffset
        toOffset = $uniqueOffset + 1
        rotated = $false
        bytes = 123
        logText = $LogText
    }

    return $body | ConvertTo-Json -Depth 10
}

function Invoke-IngestRequest {
    param(
        [string]$Body,
        [string]$RequestSecret = $Secret,
        [string]$RequestUserAgent = $UserAgent,
        [string]$ContentType = "application/json"
    )

    try {
        $response = Invoke-RestMethod `
            -Uri $Endpoint `
            -Method POST `
            -ContentType $ContentType `
            -Headers @{
                "User-Agent" = $RequestUserAgent
                "X-Log-Auth" = $RequestSecret
            } `
            -Body $Body

        return @{
            StatusCode = 200
            Body = $response
            RawError = $null
        }
    } catch {
        $statusCode = $null
        $errorBody = $null
        $rawBody = $null

        if ($_.Exception.Response -ne $null) {
            $statusCode = [int]$_.Exception.Response.StatusCode
        }

        if ($_.ErrorDetails -ne $null -and $_.ErrorDetails.Message -ne $null -and $_.ErrorDetails.Message -ne "") {
            $rawBody = $_.ErrorDetails.Message
        }

        if ($rawBody -eq $null -or $rawBody -eq "") {
            try {
                $stream = $_.Exception.Response.GetResponseStream()

                if ($stream -ne $null) {
                    $reader = New-Object System.IO.StreamReader($stream)
                    $rawBody = $reader.ReadToEnd()
                }
            } catch {
                $rawBody = $null
            }
        }

        if ($rawBody -ne $null -and $rawBody -ne "") {
            try {
                $errorBody = $rawBody | ConvertFrom-Json
            } catch {
                $errorBody = $null
            }
        }

        return @{
            StatusCode = $statusCode
            Body = $errorBody
            RawBody = $rawBody
            RawError = $_
        }
    }
}

function Assert-Equal {
    param(
        [string]$Name,
        $Actual,
        $Expected
    )

    if ($Actual -eq $Expected) {
        Write-Host "[PASS] $Name" -ForegroundColor Green
        $script:Passed++
    } else {
        Write-Host "[FAIL] $Name" -ForegroundColor Red
        Write-Host "       Expected: $Expected" -ForegroundColor DarkYellow
        Write-Host "       Actual:   $Actual" -ForegroundColor DarkYellow
        $script:Failed++
    }
}

function Assert-True {
    param(
        [string]$Name,
        [bool]$Condition
    )

    if ($Condition) {
        Write-Host "[PASS] $Name" -ForegroundColor Green
        $script:Passed++
    } else {
        Write-Host "[FAIL] $Name" -ForegroundColor Red
        $script:Failed++
    }
}

Write-Host ""
Write-Host "logs-devices ingest local tests" -ForegroundColor Cyan
Write-Host "Endpoint: $Endpoint"
Write-Host ""

# Test 1: POST valido
Write-Host "Test 1: valid POST" -ForegroundColor Cyan

$validBody = New-TestBody
$response = Invoke-IngestRequest -Body $validBody

Assert-Equal "valid POST returns HTTP 200-like success wrapper" $response.StatusCode 200
Assert-Equal "valid POST ok=true" $response.Body.ok $true
Assert-Equal "valid POST duplicate=false" $response.Body.duplicate $false
Assert-True "valid POST has ingest_id" ($response.Body.ingest_id -gt 0)
Assert-Equal "valid POST line_count=1" $response.Body.line_count 1
Assert-Equal "valid POST parsed_error=0" $response.Body.parsed_error 0

# Test 2: POST duplicado
Write-Host ""
Write-Host "Test 2: duplicate POST" -ForegroundColor Cyan

$responseDuplicate = Invoke-IngestRequest -Body $validBody

Assert-Equal "duplicate POST returns success" $responseDuplicate.StatusCode 200
Assert-Equal "duplicate POST ok=true" $responseDuplicate.Body.ok $true
Assert-Equal "duplicate POST duplicate=true" $responseDuplicate.Body.duplicate $true
Assert-Equal "duplicate POST same ingest_id" $responseDuplicate.Body.ingest_id $response.Body.ingest_id

# Test 3: secret incorrecto
Write-Host ""
Write-Host "Test 3: invalid secret" -ForegroundColor Cyan

$bodyForInvalidSecret = New-TestBody
$responseInvalidSecret = Invoke-IngestRequest -Body $bodyForInvalidSecret -RequestSecret "wrong-secret"

Assert-Equal "invalid secret HTTP 401" $responseInvalidSecret.StatusCode 401
Assert-Equal "invalid secret ok=false" $responseInvalidSecret.Body.ok $false
Assert-Equal "invalid secret error code" $responseInvalidSecret.Body.error "invalid_secret"

# Test 4: User-Agent incorrecto
Write-Host ""
Write-Host "Test 4: invalid User-Agent" -ForegroundColor Cyan

$bodyForInvalidUa = New-TestBody
$responseInvalidUa = Invoke-IngestRequest -Body $bodyForInvalidUa -RequestUserAgent "Bad-Agent"

Assert-Equal "invalid User-Agent HTTP 401" $responseInvalidUa.StatusCode 401
Assert-Equal "invalid User-Agent ok=false" $responseInvalidUa.Body.ok $false
Assert-Equal "invalid User-Agent error code" $responseInvalidUa.Body.error "invalid_user_agent"

# Test 5: JSON malformado
Write-Host ""
Write-Host "Test 5: malformed JSON" -ForegroundColor Cyan

$malformedBody = "{ this is not valid json"
$responseMalformed = Invoke-IngestRequest -Body $malformedBody

Assert-Equal "malformed JSON HTTP 400" $responseMalformed.StatusCode 400
Assert-Equal "malformed JSON ok=false" $responseMalformed.Body.ok $false
Assert-Equal "malformed JSON error code" $responseMalformed.Body.error "malformed_json"

# Test 6: sin bridgeId
Write-Host ""
Write-Host "Test 6: missing bridgeId" -ForegroundColor Cyan

$missingBridgeBody = @{
    message = "bridge_logs"
    bridgeName = "WalkerPisa Bridge"
    sentAt = (Get-Date).ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ss.fffZ")
    fromOffset = 1
    toOffset = 2
    rotated = $false
    bytes = 123
    logText = "[2026-05-07 09:30:00] [SENSOR] INFO: [AA:BB:CC:DD:EE:FF] TELEMETRY -> T=22.5 | H=40"
} | ConvertTo-Json -Depth 10

$responseMissingBridge = Invoke-IngestRequest -Body $missingBridgeBody

Assert-Equal "missing bridgeId HTTP 422" $responseMissingBridge.StatusCode 422
Assert-Equal "missing bridgeId ok=false" $responseMissingBridge.Body.ok $false
Assert-Equal "missing bridgeId error code" $responseMissingBridge.Body.error "missing_bridge_id"

# Test 7: sin logText
Write-Host ""
Write-Host "Test 7: missing logText" -ForegroundColor Cyan

$missingLogTextBody = @{
    message = "bridge_logs"
    bridgeId = "120"
    bridgeName = "WalkerPisa Bridge"
    sentAt = (Get-Date).ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ss.fffZ")
    fromOffset = 1
    toOffset = 2
    rotated = $false
    bytes = 123
} | ConvertTo-Json -Depth 10

$responseMissingLogText = Invoke-IngestRequest -Body $missingLogTextBody

Assert-Equal "missing logText HTTP 422" $responseMissingLogText.StatusCode 422
Assert-Equal "missing logText ok=false" $responseMissingLogText.Body.ok $false
Assert-Equal "missing logText error code" $responseMissingLogText.Body.error "missing_log_text"

Write-Host ""
Write-Host "Summary" -ForegroundColor Cyan
Write-Host "Passed: $script:Passed" -ForegroundColor Green
Write-Host "Failed: $script:Failed" -ForegroundColor $(if ($script:Failed -eq 0) { "Green" } else { "Red" })

if ($script:Failed -gt 0) {
    exit 1
}

exit 0