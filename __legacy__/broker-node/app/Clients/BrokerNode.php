<?php

namespace App\Clients;

require_once("iota-php-port/PrepareTransfers.php");
require_once("requests/IriData.php");
require_once("requests/IriWrapper.php");
require_once("requests/NodeMessenger.php");

// This is a temporary hack to make the above required files work in this
// namespace. We can clean this up after testnet.
use Segment;
use \Exception;
use App\Clients\requests\IriData;
use App\Clients\requests\IriWrapper;
use App\Clients\requests\NodeMessenger;
use \PrepareTransfers;
use \stdClass;
use App\HookNode;
use App\DataMap;

class BrokerNode
{
    public static $chunksToAttach = null;
    public static $chunksToVerify = null;

    public static $ChunkEventsRecord = null;

    public static function processChunks(&$chunks, $attachIfAlreadyAttached = false)
    {
        BrokerNode::getOwnIP();

        if (!is_array($chunks)) {
            $chunks = array($chunks);
        }

        $addresses = array();

        if (is_array($chunks[0])) {
            // in case this is coming from CheckChunkStatus which
            // will send an array of arrays
            foreach ($chunks as $key => $value) {
                $chunks[$key] = (object)$value;
                $addresses[] = $chunks[$key]->address;
            }
        } else {
            $addresses = array_map(function ($n) {
                return $n->address;
            }, $chunks);
        }

        if ($attachIfAlreadyAttached == false) {
            $filteredChunks = self::filterUnattachedChunks($addresses, $chunks);
            $chunksToSend = $filteredChunks->unattachedChunks;
            unset($filteredChunks);
        } else {
            $chunksToSend = $chunks;
        }

        if (count($chunksToSend) != 0) {
            $request = self::buildTransactionData($chunksToSend);
            $updated_chunks = self::sendToHookNode($chunksToSend, $request);

            DataMap::updateChunksPending($updated_chunks);

            return is_null($updated_chunks)
                ? ['hooknode_unavailable', null]
                : ['success', $updated_chunks];

        } else {
            return ['already_attached', null];
        }
    }

    public static function filterUnattachedChunks($addresses, $chunks)
    {
        $command = new \stdClass();
        $command->command = "findTransactions";
        $command->addresses = $addresses;

        $result = IriWrapper::makeRequest($command);

        if (!is_null($result) && property_exists($result, 'hashes')) {

            $returnVal = new \stdClass();
            $returnVal->attachedChunks = array();
            $returnVal->unattachedChunks = array();

            if (count($result->hashes) == 0) {
                $returnVal->unattachedChunks = $chunks;
            } else {
                $txObjects = self::getTransactionObjects($result->hashes);

                $addressesOnTangle = array_map(function ($n) {
                    return $n->address;
                }, $txObjects);

                foreach ($chunks as $key => $value) {

                    in_array($value->address, $addressesOnTangle) ?
                        $returnVal->attachedChunks[] = $value :
                        $returnVal->unattachedChunks[] = $value;
                }
            }
            return $returnVal;
        } else {
            throw new \Exception(
                "BrokerNode::filterUnattachedChunks failed." .
                "\n\tIRI.findTransactions" .
                "\n\t\tcommand: {$command->command}" .
                "\n\t\tresult: {$result}"
            );
        }
    }

    public static function dataNeedsAttaching($request)
    {
        //this method intended to be used to check a single chunk

        $command = new \stdClass();
        $command->command = "findTransactions";
        $command->addresses = array($request->address);

        $result = IriWrapper::makeRequest($command);

        if (!is_null($result) && property_exists($result, 'hashes')) {
            return count($result->hashes) == 0;
        } else {
            throw new \Exception(
                "BrokerNode::dataNeedsAttaching failed." .
                "\n\tIRI.findTransactions" .
                "\n\t\tcommand: {$command->command}" .
                "\n\t\tresult: {$result}"
            );
        }
    }

    private static function buildTransactionData($chunks)
    {
        if (!is_array($chunks)) {
            $chunks = array($chunks);
        }

        $trytesToBroadcast = NULL;
        $request = new \stdClass();
        $request->transfers = array();

        foreach ($chunks as $key => $chunk) {
            $request->transfers[$key] = new \stdClass();
            $request->transfers[$key]->Value = IriData::$txValue;
            $request->transfers[$key]->Tag = IriData::$oysterTag;
            $request->transfers[$key]->Address = $chunk->address;
            $request->transfers[$key]->Message = $chunk->message;
        }

        if (!is_null($request->transfers)) {
            self::getTransactionsToApprove($request);
        }

        return $request;
    }

    private static function getTransactionsToApprove(&$request)
    {
//        $tips = Tips::getNextTips();
//
//        if ($tips != null && $tips[0] != null && $tips[1] != null) {
//            $request->trunkTransaction = $tips[1];
//            $request->branchTransaction = $tips[0];
//        } else {
        $command = new \stdClass();
        $command->command = "getTransactionsToApprove";
        $command->depth = IriData::$depthToSearchForTxs;

        $result = IriWrapper::makeRequest($command);

        if (!is_null($result) && property_exists($result, 'branchTransaction')) {
            //switching trunk and branch
            //do we do this randomly or every time?
            $request->trunkTransaction = $result->branchTransaction;
            $request->branchTransaction = $result->trunkTransaction;
        } else {
            throw new \Exception('getTransactionToApprove failed! ' . $result->error);
        }
        //}
    }

    private static function selectHookNode()
    {
        $nextNode = HookNode::getNextReadyNode();
        return ['ip_address' => $nextNode->ip_address];
    }

    private static function getBroadcastingHookNodes()
    {
        $ip_addresses = array();

        $broadcastingNodes =
            HookNode::getBroadcastingHookNodes(['score_weight' => .5]);

        foreach ($broadcastingNodes as $node) {
            $ip_addresses[] = $node->ip_address;
        }

        return $ip_addresses;
    }

    private static function sendToHookNode(&$chunks, $request)
    {
        $hooknode = self::selectHookNode();

        if (empty($hooknode)) {
            return null;
        }

        $hookNodeUrl = $hooknode['ip_address'];
        $hookNodes = array("http://" . $hookNodeUrl . ":3000/attach/");

        $broadcastingNodes = self::getBroadcastingHookNodes();

        $tx = $request;
        $tx->command = 'attachToTangle';
        $tx->broadcastingNodes = $broadcastingNodes;

        NodeMessenger::sendMessageToNodesAndContinue($tx, $hookNodes);

        HookNode::incrementChunksProcessed($hookNodeUrl, count($chunks));

        //record event
        Segment::track([
            "userId" => $GLOBALS['ip_address'],
            "event" => "chunk_sent_to_hook",
            "properties" => [
                "hooknode_url" => $hookNodeUrl,
                "chunk_idx" => array_map(function ($chunk) {
                    return $chunk->chunk_idx;
                }, $chunks)
            ]
        ]);

        array_walk($chunks, function ($chunk) use ($hookNodeUrl, $request) {
            $chunk->hookNodeUrl = $hookNodeUrl;
            $chunk->trunkTransaction = $request->trunkTransaction;
            $chunk->branchTransaction = $request->branchTransaction;
        });

        return $chunks;
    }

    public static function verifyChunkMessagesMatchRecord($chunks)
    {
        return self::verifyChunksMatchRecord($chunks, false);
    }

    public static function verifyChunksMatchRecord($chunks, $checkBranchAndTrunk = true)
    {
        if (!is_array($chunks)) {
            $chunks = array($chunks);
        }

        $addresses = array();

        // this seems inefficient but I tried other ways and they didn't work
        // if you replace this with something better please test that the hook node
        // scores in the db still update

        if (is_array($chunks[0])) {

            // in case this is coming from CheckChunkStatus which
            // will send an array of arrays
            foreach ($chunks as $key => $value) {
                $chunks[$key] = (object)$value;
                $addresses[] = $chunks[$key]->address;
            }
        } else {
            $addresses = array_map(function ($n) {
                return $n->address;
            }, $chunks);
        }

        $command = new \stdClass();
        $command->command = "findTransactions";
        $command->addresses = $addresses;

        $result = IriWrapper::makeRequest($command);

        $chunkResults = new \stdClass();

        $chunkResults->matchesTangle = array();
        $chunkResults->doesNotMatchTangle = array();
        $chunkResults->notAttached = array();

        if (!is_null($result) && property_exists($result, 'hashes') &&
            count($result->hashes) != 0) {

            $txObjects = self::getTransactionObjects($result->hashes);
            $foundAddresses = array_map(function ($n) {
                return $n->address;
            }, $txObjects);

            foreach ($chunks as $chunk) {

                $matchingTxObjects = array_filter($txObjects, function ($tx) use ($chunk, $checkBranchAndTrunk) {
                    return $tx->address == $chunk->address && self::chunksMatch($tx, $chunk, $checkBranchAndTrunk);
                });

                if (count($matchingTxObjects) != 0) {

                    $chunkResults->matchesTangle[] = $chunk;
                } else if (in_array($chunk->address, $foundAddresses)) {

                    //found address on tangle but not a match
                    $chunkResults->doesNotMatchTangle[] = $chunk;
                } else {

                    $chunkResults->notAttached[] = $chunk;
                }
            }

            return $chunkResults;

        } else if (!is_null($result) && property_exists($result, 'hashes') &&
            count($result->hashes) == 0) {

            $chunkResults->notAttached = $chunks;
            return $chunkResults;
        } else {
            $error = '';
            foreach ($result as $key => $value) {
                if (is_array($value)) {
                    $error .= $key . ": \n" . implode("\n", $value) . "\n\n";
                } else {
                    $error .= $key . ': ' . $value . "\n";
                }
                $error .= "\n";
            }

            throw new \Exception('verifyChunkMatchesRecord failed!' . $error);
        }
    }

    private static function chunksMatch($chunkOnTangle, $chunkOnRecord, $checkBranchAndTrunk)
    {
        if ($checkBranchAndTrunk == true) {
            return self::messagesMatch($chunkOnTangle->signatureMessageFragment, $chunkOnRecord->message) &&
                $chunkOnTangle->trunkTransaction == $chunkOnRecord->trunkTransaction &&
                $chunkOnTangle->branchTransaction == $chunkOnRecord->branchTransaction;
        } else {
            return self::messagesMatch($chunkOnTangle->signatureMessageFragment, $chunkOnRecord->message);
        }
    }

    private static function messagesMatch($messageOnTangle, $messageOnRecord)
    {
        $lengthOfOriginalMessage = strlen($messageOnRecord);

        return (substr($messageOnTangle, 0, $lengthOfOriginalMessage) == $messageOnRecord) &&
            !(strlen(str_replace('9', '', substr($messageOnTangle, $lengthOfOriginalMessage))) > 0);
    }

    private static function getTransactionObjects($hashes)
    {
        $command = new \stdClass();
        $command->command = "getTrytes";
        $command->hashes = $hashes;

        $result = IriWrapper::makeRequest($command);

        if (!is_null($result) && property_exists($result, 'trytes') &&
            count($result->trytes) != 0) {
            $txObjects = array();
            foreach ($result->trytes as $key => $value) {
                $txObjects[] = \Utils::transactionObject($value);
            }
            return $txObjects;
        } else {
            throw new \Exception('getTransactionObjects failed!');
        }
    }

    public static function getOwnIP()
    {

        if (!isset($GLOBALS['ip_address'])) {
            // Pull contents from ip6.me
            $file = file_get_contents('http://ip6.me/');

            // Trim IP based on HTML formatting
            $pos = strpos($file, '+3') + 3;
            $ip = substr($file, $pos, strlen($file));

            // Trim IP based on HTML formatting
            $pos = strpos($ip, '</');
            $ip = substr($ip, 0, $pos);

            $GLOBALS['ip_address'] = $ip;
        }
    }
}
