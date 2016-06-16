<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

use Httpful\Request;
use Monolog\Logger;
use Monolog\Handler\LogglyHandler;
use Monolog\Formatter\LogglyFormatter;
use Smalot\PdfParser\Parser;

define('LOCAL_PDF_FILENAME', 'runlog.pdf');

$log = new Logger('PubRunCounter');
$log->pushHandler(new LogglyHandler('28ae9802-c9ee-47cd-a7b9-366901aa1841/tag/monolog', Logger::INFO));

try {
    $log->addInfo('Started Pub Run Counter Script.');

    $config = loadConfig();
    if (!is_array($config) || count($config) === 0) {
        throw new Exception("Need config keys and values!");
    }

    if ($config['enabled'] !== true) return;

    // Get the Pub's page to parse for PDF file available
    $pageText = Request::get($config['pageLink'])->send();

    // Try to parse the page for PDF file link
    $dom = new DOMDocument();
    $dom->loadHTML($pageText->body);

    $links = $dom->getElementsByTagName('a');

    foreach ($links as $linkNode) {
        if (stripos($linkNode->nodeValue, 'runlog') === false) continue;

        $pdfFileLink = $linkNode->attributes['href']->value;

        if ($pdfFileLink === '' || stripos($pdfFileLink, '.pdf') === false) {
            throw new Exception('No RUNLOG PDF link found from the web page!');
        }

        $fileName = basename($pdfFileLink);
        if ($fileName !== '' && $fileName === trim(file_get_contents('filename.txt'))) continue;

        file_put_contents('filename.txt', $fileName);

        // Download the file
        $file = file_put_contents(LOCAL_PDF_FILENAME, file_get_contents($pdfFileLink));

        if ($file === false) {
            throw new Exception('Failed to download the RUNLOG PDF file!');
        }

        // Parse pdf file and build necessary objects.
        $parser = new Parser();
        $pdf    = $parser->parseFile(LOCAL_PDF_FILENAME);
        $text = $pdf->getText();

        if (($pos = strpos($text, $config['searchName'])) === false) {
            throw new Exception('Could not find your name in the file! Did you really run?');
        }

        // My name with the run count at the end
        $me = substr($text, $pos, strpos($text, PHP_EOL, $pos) - $pos);

        // Get the run count
        $runCount = intval(substr($me, 8));

        // Post the count to Numerous.
        // NUMEROUS HAS SHUT DOWN!!!
        // $response = Request::post("https://api.numerousapp.com/v2/metrics/{$config['numerousMetricId']}/events")
        //     ->sendsJson()                                       // tell it we're sending (Content-Type) JSON...
        //     ->authenticateWith($config['numerousApiKey'], '')   // authenticate with basic auth...
        //     ->body('{"value":"' . $runCount . '"}')             // attach a body/payload...
        //     ->send();

        // if ($response->code === 429) {
        //     throw new Exception('Numerous API rate limit: Too Many Requests');
        // }

        $log->addInfo("Pub run count updated to {$runCount}");
    }

    return 0;
} catch (Exception $e) {
    echo $e->getMessage();

    $log->addError($e->getMessage());

    return 1; // So the console will know that it's a failed execution
} finally {
    // Janitor
    if (file_exists(LOCAL_PDF_FILENAME)) {
        unlink(LOCAL_PDF_FILENAME);
    }
}
