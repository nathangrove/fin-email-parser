<?php

$version = '1.0.2';

$firefly = getenv('FF_URL');
$ff_token = getenv('FF_TOKEN');

$accounts = [];

$server = getenv('MAIL_SERVER');
$username = getenv('MAIL_USER');
$password = getenv('MAIL_PASS');



// signal handler function
function sig_handler($signo)
{
    global $run;
    $run = false;
}

echo "Installing signal handler...\n";

// setup signal handlers
pcntl_signal(SIGTERM, "sig_handler");
pcntl_signal(SIGHUP, "sig_handler");
pcntl_signal(SIGUSR1, "sig_handler");


$run = true;
$interval = 60;

$runs = 0;

# max number of runs before exiting
$max = isset($argv[1]) ? $argv[1] : 0;

print date("[Y-m-d H:i:s]") . " Init transaction parser $version\n";

while ($run && ($max == 0 || $runs < $max)) {

    $runs++;

    print date("[Y-m-d H:i:s]") . " Checking for transactions ($runs)...\n";
    $imapResource = imap_open('{' . $server . ':993/imap/ssl}INBOX', $username, $password);


    //If the imap_open function returns a boolean FALSE value,
    //then we failed to connect.
    if ($imapResource === false) {
        //If it failed, throw an exception that contains
        //the last imap error.
        throw new Exception(imap_last_error());
    }

    //If we get to this point, it means that we have successfully
    //connected to our mailbox via IMAP.

    //Lets get all emails that were received since a given date.
    $search = 'UNSEEN'; // "' . date("j F Y", strtotime("-7 days")) . '"';
    $emails = imap_search($imapResource, $search);

    //If the $emails variable is not a boolean FALSE value or
    //an empty array.
    $transactions = [];
    if (!empty($emails)) {

        //Loop through the emails.
        foreach ($emails as $email) {


            //Fetch an overview of the email.
            $overview = imap_fetch_overview($imapResource, $email);
            $overview = $overview[0];

            print date("[Y-m-d H:i:s]") . " Parsing message ($email): $overview->subject\n";



            if (strstr($overview->subject, 'Bank of America Alert')) {

                # check if this is a with draw
                if (strstr($overview->subject, 'withdrawal'))
                    $transaction = parseBOAWithdrawal(imap_fetchbody($imapResource, $email, null, FT_PEEK));

                # maybe it is a deposit (pay check)? - These don't include a dollar amount, but we can fix it with FF rules.
                else if (strstr($overview->subject, 'Direct deposit'))
                    $transaction = parseBOADeposit(imap_fetchbody($imapResource, $email, null, FT_PEEK));

                # stock portfolio daily updates. Update account value
            } else if (strstr($overview->subject, 'Daily Portfolio Update')) {
                $transaction = parseTDDailyUpdate(imap_fetchbody($imapResource, $email, null, FT_PEEK));

                # CC charge
            } else if (strstr($overview->from, 'no.reply.alerts@chase.com')) {
                $transaction = parseChaseWithdrawal($overview, imap_fetchbody($imapResource, $email, 1, FT_PEEK));

            } else {
                # flag it if it dind't work...
                print date("[Y-m-d H:i:s]") . " Not sure what this message is...skipping...\n";
                imap_setflag_full($imapResource, $email, "\\Flagged \\Seen");
                continue;
            }
            if ($transaction)
                $transactions[] = $transaction;

            # set it so we don't see it next time
            imap_setflag_full($imapResource, $email, "\\Seen");

            unset($overview);
            unset($email);
            unset($transaction);

        }

        imap_close($imapResource);
        unset($imapResource);
    }

    unset($emails);
    unset($search);

    foreach ($transactions as $transaction) {
        global $ff_token, $firefly;

        $payload = [];
        $payload['transactions'] = [];
        $payload['transactions'][0]['type'] = $transaction->type == 'debit' ? 'withdrawal' : 'deposit';
        $payload['transactions'][0]['description'] = $transaction->description;
        $payload['transactions'][0]['date'] = $transaction->date;
        $payload['transactions'][0]['amount'] = str_replace(',', '', $transaction->amount);
        $payload['transactions'][0]['notes'] = "Created with parser $version";

        # match the account
        $acct_id = getAccountByNumber($transaction->account);

        if ($payload['transactions'][0]['type'] == 'withdrawal')
            $payload['transactions'][0]['source_id'] = $acct_id;
        if ($payload['transactions'][0]['type'] == 'deposit')
            $payload['transactions'][0]['destination_id'] = $acct_id;

        if (!$acct_id) {
            print date("[Y-m-d H:i:s]") . " Acct Num not found: $transaction->account\n";
            continue;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_URL, "http://$firefly/api/v1/transactions");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $ff_token,
            'Content-Type: application/json',
            'Accept: application/json'
        ));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http_code != 200) {
            print date("[Y-m-d H:i:s]") . " Bad response ($http_code) posting transaction " . $response . "\n" . date("[Y-m-d H:i:s]") . " Payload: " . json_encode($payload) . "\n\n";
            continue;
        }

        $transaction->id = json_decode($response)->data->id;
        print date("[Y-m-d H:i:s]") . " Transaction created $transaction->id\n";

        #uploadAttachment($transaction);

        unset($payload);
        unset($acct_id);
        unset($transaction);
        unset($ch);
        unset($http_code);
        unset($response);

    }


    unset($transactions);



    print date("[Y-m-d H:i:s]") . " Done processing transactions\n";
    sleep($interval);

}

function uploadAttachment($transaction)
{
    global $ff_token, $firefly, $version;


    # create transaction record
    $payload = array(
        'filename' => 'transaction_alert.eml',
        'attachable_type' => 'TransactionJournal',
        'attachable_id' => $transaction->id,
        'title' => 'Transaction Alert',
        'notes' => "Created with parser $version"
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_URL, "http://$firefly/api/v1/attachments");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer ' . $ff_token,
        'Content-Type: application/json',
        'Accept: application/json'
    ));
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code != 200) {
        print "Bad response ($http_code): \n" . $response . "\n\nPayload: " . json_encode($payload) . "\n\n";
        return;
    }
    $upload_id = json_decode($response)->data->id;



    # upload attachment
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_URL, "http://$firefly/api/v1/attachments/$upload_id/upload");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer ' . $ff_token,
        'Content-type text/plain',
        'Accept: application/json,text/plain'
    ));
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $transaction->attachment);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code != 204) {
        print "Bad response ($http_code): \n" . $response . "\n\nPayload: " . $transaction->attachment . "\n\n";
    }


}

function getAccountByNumber($num)
{
    global $accounts, $ff_token, $firefly;

    if (!count($accounts)) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_URL, "http://$firefly/api/v1/accounts");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $ff_token));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        $accounts = json_decode($response)->data;
    }

    foreach ($accounts as $account) {
        if (strstr($account->attributes->account_number, $num)) {
            return $account->id;
        }
    }
    return false;
}

function parseChaseWithdrawal($overview, $message)
{

    $res = new stdClass();
    $res->type = 'debit';

    $re = '/\(\.\.\.(\d{4})\)/ms';
    preg_match_all($re, $message, $matches, PREG_SET_ORDER, 0);
    $res->account = $matches[0][1];

    $re = '/\$(\d+\.\d{2})\stransaction/ms';
    preg_match_all($re, $message, $matches, PREG_SET_ORDER, 0);
    $res->amount = $matches[0][1];

    $vendor = explode('transaction with ', $overview->subject)[1];
    $res->description = $vendor;

    $res->date = date("Y-m-d H:i:s", strtotime($overview->date));

    $res->attachment = $message;
    return $res;
}

function parseBOADeposit($message)
{

    $res = new stdClass();
    $res->type = 'credit';

    # date parse
    $re = '/\s(.*)\s\(envelope-from/m';
    preg_match_all($re, $message, $matches, PREG_SET_ORDER, 0);
    $res->date = date("Y-m-d H:i:s", strtotime($matches[0][1]));

    # get the account number
    $re = '/Account ending in (\d{4})/m';
    preg_match_all($re, $message, $matches, PREG_SET_ORDER, 0);
    $res->account = trim($matches[0][1]);

    # who the deposit came from
    $re = '/received from:\s(.*)<\/font/m';
    preg_match_all($re, $message, $matches, PREG_SET_ORDER, 0);
    $res->description = trim($matches[0][1]);

    # hard code to 1 since these emails do not include amount
    $res->amount = 1;

    # add the whole email as an attachment
    $res->attachment = $message;

    return $res;
}

function parseBOAWithdrawal($message)
{
    $res = new stdClass();
    $res->type = 'debit';

    $re = '/<li>.*">(.*)<\/font><\/li>/m';
    preg_match_all($re, $message, $matches, PREG_SET_ORDER, 0);


    $dateParts = explode('-', trim($matches[0][1]));

    $res->description = trim($matches[1][1]);
    $res->date = date("Y-m-d H:i:s", strtotime("$dateParts[3]-$dateParts[0]-$dateParts[1]"));
    $res->amount = trim(explode('$', $matches[2][1])[1]);

    $re = '/Checking Account ending in (\d{4})/m';
    preg_match_all($re, $message, $matches, PREG_SET_ORDER, 0);
    $res->account = trim($matches[0][1]);

    $res->attachment = $message;
    return $res;
}

function parseTDDailyUpdate($message)
{

    $res = new stdClass();
    $res->type = 'credit';


    $re = '/Portfolio is currently (down|up) (\d+\.\d+)/m';
    preg_match_all($re, $message, $matches, PREG_SET_ORDER, 0);

    $direction = trim($matches[0][1]);
    $amount = trim($matches[0][2]);

    if ($direction == 'down')
        $res->type = 'debit';

    $res->amount = round($amount, 2);
    $res->description = "Portfolio update " . date("Y-m-d");

    # hard coded account #
    $res->account = '1234';

    if ($direction == 'down')
        $res->type = 'debit';

    $res->date = date("Y-m-d H:i:s");

    $res->attachment = $message;

    return $res;
}
