<?php

namespace React\Dns\Resolver;

use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\Query;
use React\Dns\RecordNotFoundException;
use React\Dns\Model\Message;

class Resolver
{
    private $nameserver;
    private $executor;

    public function __construct($nameserver, ExecutorInterface $executor)
    {
        $this->nameserver = $nameserver;
        $this->executor = $executor;
    }

    public function resolve($domain)
    {
        $query = new Query($domain, Message::TYPE_A, Message::CLASS_IN, time());

        return $this->executor
            ->query($this->nameserver, $query)
            ->then(function (Message $response) use ($query) {
                return $this->extractAddress($query, $response);
            });
    }

    /**
     * Look up dns record
     *
     * @param $domain
     * @param $type of record A, NS, MX, SOA, PTR, CNAME etc..
     */
    public function lookup($domain, $type = Message::TYPE_ANY)
    {
        $query = new Query($domain, $type, Message::CLASS_IN, time());

        return $this->executor
            ->query($this->nameserver, $query)
            ->then(function (Message $response) use ($query)
            {
                return $response;
            });
    }

    /**
     * Reverse IP lookup
     *
     * @param string $ip 8.8.8.8
     */
    public function reverse($ip)
    {
        $that = $this;

        if (strpos($ip, '.') !== false)
            $arpa = strrev($ip) . '.in-addr.arpa';
        /* @TODO: ipv6 implementation
        else
        {
            // Alnitak @ http://stackoverflow.com/a/6621473/394870
            $addr = inet_pton($ip);
            $unpack = unpack('H*hex', $addr);
            $hex = $unpack['hex'];
            $arpa = implode('.', array_reverse(str_split($hex))) . '.ip6.arpa';
        }*/

        #$arpa .= '.';
        $query = new Query($arpa, Message::TYPE_PTR, Message::CLASS_IN, time());

        return $this->executor
            ->query($this->nameserver, $query )
            ->then(function (Message $response) use ($that)
            {
                return $response;
            });
    }

    public function extractAddress(Query $query, Message $response)
    {
        $answers = $response->answers;

        $addresses = $this->resolveAliases($answers, $query->name);

        if (0 === count($addresses)) {
            $message = 'DNS Request did not return valid answer.';
            throw new RecordNotFoundException($message);
        }

        $address = $addresses[array_rand($addresses)];
        return $address;
    }

    public function resolveAliases(array $answers, $name)
    {
        $named = $this->filterByName($answers, $name);
        $aRecords = $this->filterByType($named, Message::TYPE_A);
        $cnameRecords = $this->filterByType($named, Message::TYPE_CNAME);

        if ($aRecords) {
            return $this->mapRecordData($aRecords);
        }

        if ($cnameRecords) {
            $aRecords = array();

            $cnames = $this->mapRecordData($cnameRecords);
            foreach ($cnames as $cname) {
                $targets = $this->filterByName($answers, $cname);
                $aRecords = array_merge(
                    $aRecords,
                    $this->resolveAliases($answers, $cname)
                );
            }

            return $aRecords;
        }

        return array();
    }

    private function filterByName(array $answers, $name)
    {
        return $this->filterByField($answers, 'name', $name);
    }

    private function filterByType(array $answers, $type)
    {
        return $this->filterByField($answers, 'type', $type);
    }

    private function filterByField(array $answers, $field, $value)
    {
        return array_filter($answers, function ($answer) use ($field, $value) {
            return $value === $answer->$field;
        });
    }

    private function mapRecordData(array $records)
    {
        return array_map(function ($record) {
            return $record->data;
        }, $records);
    }
}
