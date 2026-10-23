<?php
declare(strict_types=1);

namespace Hitrov;


use Hitrov\Exception\ApiCallException;
use Hitrov\Exception\CurlException;
use Hitrov\Interfaces\CacheInterface;
use Hitrov\Exception\TooManyRequestsWaiterException;
use Hitrov\Interfaces\TooManyRequestsWaiterInterface;
use Hitrov\OCI\Signer;
use JsonException;

class OciApi
{
    /**
     * @var array
     */
    private $existingInstances;

    private CacheInterface $cache;
    private TooManyRequestsWaiterInterface $waiter;

    private array $responseHeaders = [];

    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    /**
     * @param OciConfig $config
     * @param string $shape
     * @param string $sshKey
     * @param string $availabilityDomain
     * @return array
     *
     * @throws ApiCallException
     * @throws JsonException
     * @throws OCI\Exception\PrivateKeyFileNotFoundException
     * @throws OCI\Exception\SignerValidateException
     * @throws OCI\Exception\SigningValidationFailedException
     * @throws CurlException
     */
    public function createInstance(
        OciConfig $config,
        string $shape,
        string $sshKey,
        string $availabilityDomain,
        ?string $faultDomain = null
    ): array
    {
        if (isset($this->waiter) && $this->waiter->isConfigured()) {
            if ($this->waiter->isTooEarly()) {
                throw new TooManyRequestsWaiterException(
                    "Will retry after {$this->waiter->secondsRemaining()} seconds",
                );
            }

            $this->waiter->remove();
        }

        $displayName = $config->hostname ?: 'instance-' . date('Ymd-Hi');

        $vnicDetails = [
            "assignPublicIp" => $config->assignPublicIp,
            "subnetId" => $config->subnetId,
            "assignPrivateDnsRecord" => true,
        ];

        if ($config->ipv6Enabled) {
            $vnicDetails["assignIpv6Ip"] = true;
        }

        $body = [
            "metadata" => [
                "ssh_authorized_keys" => $sshKey,
            ],
            "shape" => $shape,
            "compartmentId" => $config->tenancyId,
            "displayName" => $displayName,
            "availabilityDomain" => $availabilityDomain,
            "sourceDetails" => json_decode($config->getSourceDetails(), true),
            "createVnicDetails" => $vnicDetails,
            "agentConfig" => [
                "pluginsConfig" => [
                    [
                        "name" => "Compute Instance Monitoring",
                        "desiredState" => "ENABLED"
                    ]
                ],
                "isMonitoringDisabled" => false,
                "isManagementDisabled" => false
            ],
            "definedTags" => new \stdClass(),
            "freeformTags" => new \stdClass(),
            "instanceOptions" => [
                "areLegacyImdsEndpointsDisabled" => false
            ],
            "availabilityConfig" => [
                "recoveryAction" => "RESTORE_INSTANCE"
            ],
            "shapeConfig" => [
                "ocpus" => $config->ocpus,
                "memoryInGBs" => $config->memoryInGBs
            ]
        ];

        if ($faultDomain !== null && $faultDomain !== '') {
            $body["faultDomain"] = $faultDomain;
        }

        if ($config->ipv6Enabled) {
            $body["freeformTags"] = ["ipv6_enabled" => "true"];
        }

        $bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES);

        $baseUrl = "{$this->getBaseApiUrl($config)}/instances/";

        try {
            return $this->call($config, $baseUrl, 'POST', $bodyJson);
        } catch(ApiCallException $e) {
            $message = $e->getMessage();
            if ($e->getCode() != 429 && strpos($message, 'TooManyRequests') === false) {
                throw $e;
            }

            if (!isset($this->waiter) || !$this->waiter->isConfigured()) {
                throw $e;
            }

            $this->waiter->enable();
            throw new TooManyRequestsWaiterException($message);
        }
    }

    /**
     * @param OciConfig $config
     * @return array
     *
     * @throws ApiCallException
     * @throws JsonException
     * @throws OCI\Exception\PrivateKeyFileNotFoundException
     * @throws OCI\Exception\SignerValidateException
     * @throws OCI\Exception\SigningValidationFailedException
     */
    public function getInstances(OciConfig $config): array
    {
        $baseUrl = "{$this->getBaseApiUrl($config)}/instances/";
        $params = ['compartmentId' => $config->tenancyId];

        return $this->call($config, $baseUrl, 'GET', null, $params);
    }

    public function checkExistingInstances(OciConfig $config, array $listResponse, string $shape, int $maxRunningInstancesOfThatShape): string
    {
        $this->existingInstances = array_filter($listResponse, function ($instance) use ($shape) {
//        $unacceptableStates = ['RUNNING', 'PROVISIONING', 'STARTING', 'STOPPED', 'STOPPING', 'TERMINATING'];
            $acceptableStates = ['TERMINATED'];
            return !in_array($instance['lifecycleState'], $acceptableStates) && $instance['shape'] === $shape;
        });

        if (count($this->existingInstances) < $maxRunningInstancesOfThatShape) {
            return '';
        }

        $displayNames = array_map(function ($instance) {
            return $instance['displayName'];
        }, $this->existingInstances);
        $displayNamesString = implode(', ', $displayNames);

        $lifecycleStates = array_map(function ($instance) {
            return $instance['lifecycleState'];
        }, $this->existingInstances);
        $lifecycleStatesString = implode(', ', $lifecycleStates);

        return "Already have an instance(s) [$displayNamesString] in state(s) (respectively) [$lifecycleStatesString]. User: $config->ociUserId\n";
    }

    /**
     * @param OciConfig $config
     * @return array
     *
     * @throws ApiCallException
     * @throws JsonException
     * @throws OCI\Exception\PrivateKeyFileNotFoundException
     * @throws OCI\Exception\SignerValidateException
     * @throws OCI\Exception\SigningValidationFailedException
     */
    public function getAvailabilityDomains(OciConfig $config): array
    {
        $data = null;
        if (getenv('CACHE_AVAILABILITY_DOMAINS') && isset($this->cache)) {
            $data = $this->cache->get('getAvailabilityDomains');
        }

        if (!$data) {
            $baseUrl = "{$this->getBaseApiUrl($config, 'identity')}/availabilityDomains/";
            $params = ['compartmentId' => $config->tenancyId];

            $data = $this->call($config, $baseUrl, 'GET', null, $params);
            if (getenv('CACHE_AVAILABILITY_DOMAINS') && isset($this->cache)) {
                $this->cache->add($data, 'getAvailabilityDomains');
            }
        }

        return $data;
    }

    /**
     * @return array
     */
    public function getExistingInstances(): array
    {
        return $this->existingInstances;
    }

    public function setCache(CacheInterface $cache): void
    {
        $this->cache = $cache;
    }

    /**
     * @param OciConfig $config
     * @param string $baseUrl
     * @param string $method
     * @param string|null $body
     * @param array $params
     * @return array
     *
     * @throws ApiCallException
     * @throws JsonException
     * @throws OCI\Exception\PrivateKeyFileNotFoundException
     * @throws OCI\Exception\SignerValidateException
     * @throws OCI\Exception\SigningValidationFailedException
     * @throws CurlException
     */
    public function call(
        OciConfig $config,
        string $baseUrl = '',
        string $method = 'GET',
        ?string $body = null,
        array $params = [],
        array $requestHeaders = []
    )
    {
        $paramsString = '';
        if ($params) {
            $paramsString = '?' . http_build_query($params);
        }

        $url = "$baseUrl$paramsString";

        $signer = new Signer(
            $config->tenancyId,
            $config->ociUserId,
            $config->keyFingerPrint,
            $config->privateKeyFilename
        );

        $headers = $signer->getHeaders($url, $method, $body, 'application/json');

        // CreateIpv6 documents this header for safely replaying an uncertain POST.
        // Keep the extension narrow: callers cannot override signed/auth headers.
        if (isset($requestHeaders['opc-retry-token'])) {
            $token = $requestHeaders['opc-retry-token'];
            if (!is_string($token) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $token)) {
                throw new \InvalidArgumentException('Invalid OCI retry token.');
            }
            $headers[] = 'opc-retry-token: ' . $token;
        }

        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_MAXREDIRS => 1,
            CURLOPT_TIMEOUT => (int) (getenv('OCI_API_TIMEOUT') ?: 45),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($body) {
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }

        return HttpClient::getResponse($curlOptions, $this->responseHeaders);
    }

    public function setWaiter(TooManyRequestsWaiterInterface $waiter): void
    {
        $this->waiter = $waiter;
    }

    private function getBaseApiUrl(OciConfig $config, string $api = 'iaas'): string
    {
        return "https://$api.{$config->region}.oraclecloud.com/20160918";
    }
}
