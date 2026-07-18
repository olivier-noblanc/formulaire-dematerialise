param(
    [int]$Port = 9876,
    [string]$DocRoot = '',
    [string]$PhpBin = ''
)

$routerPath = Join-Path $DocRoot "router.php"

# Start PHP server with test environment variables
$env:APP_TEST_MODE = "1"
$env:APP_TEST_SECRET = "1"
$env:AUTH_USER = "admin@exemple.invalid"

Start-Process -FilePath $PhpBin -ArgumentList @("-S", "127.0.0.1:$Port", "-t", $DocRoot, $routerPath) -WindowStyle Hidden
