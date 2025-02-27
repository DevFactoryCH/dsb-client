<?php

/**
 * @file
 * Contains \Educa\DSB\Client\ApiClient\ClientV2.
 *
 * This client is compatible with the version 2.x of the REST API.
 */

namespace Educa\DSB\Client\ApiClient;

use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Educa\DSB\Client\ApiClient\AbstractClient;
use Educa\DSB\Client\ApiClient\ClientAuthenticationException;
use Educa\DSB\Client\ApiClient\ClientRequestException;

class ClientV2 extends AbstractClient
{

    protected $tokenExpiresOn;

    /**
     * @{inheritdoc}
     */
    public function authenticate()
    {
        if (!file_exists($this->privateKeyPath)) {
            throw new ClientAuthenticationException("Private key could not be loaded. Is the path correct ?");
        }

        $privateKeyRaw = file_get_contents($this->privateKeyPath);
        if (empty($this->privateKeyPassphrase)) {
            $privateKey = openssl_pkey_get_private($privateKeyRaw);
        } else {
            $privateKey = openssl_pkey_get_private($privateKeyRaw, $this->privateKeyPassphrase);
        }

        if (!$privateKey) {
            throw new ClientAuthenticationException("Private key could not be loaded. Is the passphrase correct ?");
        }

        $vector = md5($this->username . time());
        openssl_sign($vector, $signature, $privateKey);

        $options = [
            'form_params' => [
                'user' => $this->username,
                'signature' => base64_encode($signature),
                'vector' => $vector,
            ],
        ];

        try {
            $response = $this->post('/auth', $options);
            if ($response->getStatusCode() == 200) {
                $data = json_decode($response->getBody(), true);
                if (!empty($data['token'])) {
                    $this->tokenKey = $data['token'];
                    $this->tokenExpiresOn = $data['expire'];
                }
                else {
                    throw new ClientAuthenticationException(sprintf("Authentication failed. Status was correct, but couldn't find a token in the body. Body: %s", $response->getBody()));
                }
            } else {
                throw new ClientAuthenticationException(
                    sprintf("Authentication failed. Status: %s. Error message: %s", $response->getStatusCode(), $response->getBody()),
                    $response->getStatusCode()
                );
            }
            // @codeCoverageIgnoreStart
        } catch (GuzzleRequestException $e) {
            throw new ClientAuthenticationException(
                sprintf("Authentication failed. Status: %s. Error message: %s", $e->getResponse()->getStatusCode(), $e->getMessage()),
                $e->getResponse()->getStatusCode()
            );
            // @codeCoverageIgnoreEnd
        }

        // Support chaining.
        return $this;
    }

    /**
     * @{inheritdoc}
     */
    public function search(
        $query = '',
        array $useFacets = [],
        array $filters = [],
        array $additionalFields = [],
        $offset = 0,
        $limit = 50,
        $sortBy = 'random'
    )
    {
        if (empty($this->tokenKey)) {
            throw new ClientAuthenticationException("No token found. Cannot make a search request without a token.");
        }

        $options = [
            'query' => [
              'query' => $query,
              'facets' => empty($useFacets) ? '[]' : json_encode($useFacets),
              'filters' => empty($filters) ? '{}' : json_encode($filters),
              'additionalFields' => empty($additionalFields) ? '[]' : json_encode($additionalFields),
              'offset' => $offset,
              'limit' => $limit,
              'sortBy' => $sortBy,
            ],
        ];

        try {
            $response = $this->get('/search', $options);
            if ($response->getStatusCode() == 200) {
                return json_decode($response->getBody(), true);
            } else {
                throw new ClientRequestException(
                    sprintf("Request to /search failed. Status: %s. Error message: %s", $response->getStatusCode(), $response->getBody()),
                    $response->getStatusCode()
                );
            }
            // @codeCoverageIgnoreStart
        } catch(GuzzleRequestException $e) {
            throw new ClientRequestException(
                sprintf("Request to /search failed. Status: %s. Error message: %s", $e->getResponse()->getStatusCode(), $e->getMessage()),
                $e->getResponse()->getStatusCode()
            );
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @{inheritdoc}
     */
    public function getSuggestions($query = '', array $filters = [])
    {
        if (empty($this->tokenKey)) {
            throw new ClientAuthenticationException("No token found. Cannot fetch suggestions without a token.");
        }

        $options = [
            'query' => [
                'query' => $query,
                'filters' => empty($filters) ? '{}' : json_encode($filters),
            ],
        ];

        try {
            $response = $this->get('/suggest', $options);
            if ($response->getStatusCode() == 200) {
                return json_decode($response->getBody(), true);
            } else {
                throw new ClientRequestException(
                    sprintf("Request to /suggest failed. Status: %s. Error message: %s", $response->getStatusCode(), $response->getBody()),
                    $response->getStatusCode()
                );
            }
            // @codeCoverageIgnoreStart
        } catch(GuzzleRequestException $e) {
            throw new ClientRequestException(
                sprintf("Request to /suggest failed. Status: %s. Error message: %s", $e->getResponse()->getStatusCode(), $e->getMessage()),
                $e->getResponse()->getStatusCode()
            );
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @{inheritdoc}
     */
    public function loadDescription($lomId)
    {
        return $this->getDescription($lomId);
    }

    /**
     * @{inheritdoc}
     */
    public function getDescription($lomId)
    {
        if (empty($this->tokenKey)) {
            throw new ClientAuthenticationException("No token found. Cannot load a LOM description without a token.");
        }

        try {
            $response = $this->get('/description/' . urlencode($lomId));
            if ($response->getStatusCode() == 200) {
                return json_decode($response->getBody(), true);
            } else {
                throw new ClientRequestException(
                    sprintf("Request to /description/%s failed. Status: %s. Error message: %s", $lomId, $response->getStatusCode(), $response->getBody()),
                    $response->getStatusCode()
                );
            }
            // @codeCoverageIgnoreStart
        } catch(GuzzleRequestException $e) {
            throw new ClientRequestException(
                sprintf("Request to /description/%s failed. Status: %s. Error message: %s", $lomId, $e->getResponse()->getStatusCode(), $e->getMessage()),
                $e->getResponse()->getStatusCode()
            );
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @{inheritdoc}
     */
    public function loadOntologyData($type = 'list', array $vocabularyIds = null)
    {
        return $this->getOntologyData($type, $vocabularyIds);
    }

    /**
     * @{inheritdoc}
     */
    public function getOntologyData($type = 'list', array $vocabularyIds = null)
    {
        if (empty($this->tokenKey)) {
            throw new ClientAuthenticationException("No token found. Cannot load Ontology data without a token.");
        }

        try {
            $response = $this->get(
                "/ontology/{$type}" . (!empty($vocabularyIds) ? '/' . implode(',', $vocabularyIds) : '')
            );

            if ($response->getStatusCode() == 200) {
                return json_decode($response->getBody(), true);
            } else {
                throw new ClientRequestException(
                    sprintf("Request to /ontology/%s/%s failed. Status: %s. Error message: %s", $type, implode(',', $vocabularyIds), $response->getStatusCode(), $response->getBody()),
                    $response->getStatusCode()
                );
            }
            // @codeCoverageIgnoreStart
        } catch(GuzzleRequestException $e) {
            throw new ClientRequestException(
                sprintf("Request to /ontology/%s/%s failed. Status: %s. Error message: %s", $type, implode(',', $vocabularyIds), $e->getResponse()->getStatusCode(), $e->getMessage()),
                $e->getResponse()->getStatusCode()
            );
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @{inheritdoc}
     */
    public function loadPartners()
    {
        return $this->getPartner();
    }

    /**
     * @{inheritdoc}
     */
    public function loadPartner($partner)
    {
        return $this->getPartner($partner);
    }

    /**
     * @{inheritdoc}
     */
    public function getPartner($partner = null)
    {
        if (empty($this->tokenKey)) {
            throw new ClientAuthenticationException("No token found. Cannot load a partner without a token.");
        }

        try {
            if (isset($partner)) {
                $response = $this->get('/partner/' . urlencode($partner));
            } else {
                $response = $this->get('/partner');
            }

            if ($response->getStatusCode() == 200) {
                return json_decode($response->getBody(), true);
            } else {
                throw new ClientRequestException(
                    sprintf(
                        "Request to /partner%s failed. Status: %s. Error message: %s",
                        isset($partner) ? '/' . $partner : '',
                        $response->getStatusCode(),
                        $response->getBody()
                    ),
                    $response->getStatusCode()
                );
            }
            // @codeCoverageIgnoreStart
        } catch(GuzzleRequestException $e) {
            throw new ClientRequestException(
                sprintf(
                    "Request to /partner%s failed. Status: %s. Error message: %s",
                    isset($partner) ? '/' . $partner : '',
                    $e->getResponse()->getStatusCode(),
                    $e->getMessage()
                ),
                $e->getResponse()->getStatusCode()
            );
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @{inheritdoc}
     */
    public function putPartner($partner, $json)
    {
        if (empty($this->tokenKey)) {
            throw new ClientAuthenticationException("No token found. Cannot load a partner without a token.");
        }

        $params = [
            'body' => $json,
        ];

        try {
            $response = $this->put('/partner/' . urlencode($partner), $params);

            if ($response->getStatusCode() == 200) {
                return json_decode($response->getBody(), true);
            } else {
                throw new ClientRequestException(
                    sprintf("Request to /partner/%s failed. Status: %s. Error message: %s", $partner, $response->getStatusCode(), $response->getBody()),
                    $response->getStatusCode()
                );
            }
            // @codeCoverageIgnoreStart
        } catch(GuzzleRequestException $e) {
            throw new ClientRequestException(
                sprintf("Request to /partner/%s failed. Status: %s. Error message: %s", $partner, $e->getResponse()->getStatusCode(), $e->getMessage()),
                $e->getResponse()->getStatusCode()
            );
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @{inheritdoc}
     */
    public function validateDescription($json)
    {
        if (empty($this->tokenKey)) {
            throw new ClientAuthenticationException("No token found. Cannot validate a LOM description without a token.");
        }

        $params = [
            'form_params' => [
                'description' => $json
            ],
        ];

        try {
            $response = $this->post('/validate', $params);
            return json_decode($response->getBody(), true);
            // @codeCoverageIgnoreStart
        } catch(GuzzleRequestException $e) {
            throw new ClientRequestException(
                sprintf("Request to /validate failed. Status: %s. Error message: %s", $e->getResponse()->getStatusCode(), $e->getMessage())
            );
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @{inheritdoc}
     */
    public function postDescription($json, $catalogs = array(), $previewImage = false)
    {
        if (empty($this->tokenKey)) {
            throw new ClientAuthenticationException("No token found. Cannot create a LOM description without a token.");
        }

        // @todo DRYer, merge with putDescription() logic.
        $params = [
            'multipart' => [
                [
                    'name'     => 'description',
                    'contents' => $json,
                ],
            ],
        ];

        if ($previewImage) {
            if (file_exists($previewImage) && is_readable($previewImage)) {
                $params['multipart'][] = [
                    'name'     => 'previewImage',
                    'contents' => fopen($previewImage, 'r'),
                    'filename' => @end(explode('/', $previewImage)),
                ];
            } else {
                throw new \RuntimeException(sprintf("File %s does not exist, or is not readable.", $previewImage));
            }
        }

        if (!is_array($catalogs)) {
            throw new \RuntimeException("It seems that the 'catalogs' parameter is not correctly formatted. Skipping");
        } else if (!empty($catalogs)) {
            $this->addRequestHeader('X-DSB-CATALOGS', implode(',', array_map('trim', $catalogs)));
        }

        try {
            $response = $this->post('/description', $params);
            if ($response->getStatusCode() == 200) {
                return json_decode($response->getBody(), true);
            } else {
                throw new ClientRequestException(
                    sprintf("POST request to /description failed. Status: %s. Error message: %s", $response->getStatusCode(), $response->getBody()),
                    $response->getStatusCode()
                );
            }
            // @codeCoverageIgnoreStart
        } catch(GuzzleRequestException $e) {
            throw new ClientRequestException(
                sprintf("Post request to /description failed. Status: %s. Error message: %s", $e->getResponse()->getStatusCode(), $e->getMessage()),
                $e->getResponse()->getStatusCode()
            );
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @{inheritdoc}
     */
    public function putDescription($id, $json, $catalogs = array())
    {
        if (empty($this->tokenKey)) {
            throw new ClientAuthenticationException("No token found. Cannot update a LOM description without a token.");
        }

        $params = [
            'body' => $json,
        ];

        if (!is_array($catalogs)) {
            throw new \RuntimeException("The 'catalogs' parameter is not correctly formatted. It must be an array.");
        } else if (!empty($catalogs)) {
            $this->addRequestHeader('X-DSB-CATALOGS', implode(',', array_map('trim', $catalogs)));
        }

        try {
            $response = $this->put('/description/' . urlencode($id), $params);
            if ($response->getStatusCode() == 200) {
                return json_decode($response->getBody(), true);
            } else {
                throw new ClientRequestException(
                    sprintf("Put request to /description/%s failed. Status: %s. Error message: %s", urlencode($id), $response->getStatusCode(), $response->getBody()),
                    $response->getStatusCode()
                );
            }
            return json_decode($response->getBody(), true);
            // @codeCoverageIgnoreStart
        } catch(GuzzleRequestException $e) {
            throw new ClientRequestException(
                sprintf("Put request to /description/$id/$catalogs failed. Status: %s. Error message: %s", $e->getResponse()->getStatusCode(), $e->getMessage()),
                $e->getResponse()->getStatusCode()
            );
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @{inheritdoc}
     */
    public function deleteDescription($id)
    {
        if (empty($this->tokenKey)) {
            throw new ClientAuthenticationException("No token found. Cannot delete a LOM description without a token.");
        }

        try {
            $response = $this->delete("/description/" . urlencode($id));
            return json_decode($response->getBody(), true);
            // @codeCoverageIgnoreStart
        } catch(GuzzleRequestException $e) {
            throw new ClientRequestException(
                sprintf("Delete request to /description/$id failed. Status: %s. Error message: %s", $e->getResponse()->getStatusCode(), $e->getMessage())
            );
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @{inheritdoc}
     */
    public function loadPartnerStatistics(
        $partnerId,
        $from,
        $to,
        $aggregationMethod = 'day',
        $lomId = null,
        $limit = null,
        $offset = null
    )
    {
        return $this->getPartnerStatistics($partnerId, $from, $to, $aggregationMethod, $lomId, $limit, $offset);
    }

    /**
     * @{inheritdoc}
     */
    public function getPartnerStatistics(
        $partnerId,
        $from,
        $to,
        $aggregationMethod = 'day',
        $lomId = null,
        $limit = null,
        $offset = null
    )
    {
        if (empty($this->tokenKey)) {
            throw new ClientAuthenticationException("No token found. Cannot load partner statistics without a token.");
        }

        if (!in_array($aggregationMethod, ['day', 'month', 'year'])) {
            throw new \InvalidArgumentException("The aggregation method can only by one of the following: 'day', 'month' or 'year'. Provided: '$aggregationMethod'.");
        }

        if (strtotime($from) >= strtotime($to)) {
            throw new \InvalidArgumentException("The 'to' date must be greater than the 'from' date.");
        }

        try {
            // Prepare the URL arguments.
            $partnerId = urlencode($partnerId);
            $from = urlencode($from);
            $to = urlencode($to);
            $aggregationMethod = urlencode($aggregationMethod);

            $options = array('query' => array());

            if (!empty($offset)) {
                $options['query']['offset'] = $offset;
            }
            if (!empty($limit)) {
                $options['query']['limit'] = $limit;
            }

            $url = "/stats/$partnerId/$from/$to/$aggregationMethod";

            if (!empty($lomId)) {
                $url .= '/' . urlencode($lomId);
            }

            $response = $this->get($url, $options);
            return json_decode($response->getBody(), true);
            // @codeCoverageIgnoreStart
        } catch(GuzzleRequestException $e) {
            throw new ClientRequestException(
                sprintf("Request to /stats/$partnerId/$from/$to/$aggregationMethod failed. Status: %s. Error message: %s", $e->getResponse()->getStatusCode(), $e->getMessage())
            );
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @{inheritdoc}
     */
    public function uploadFile($filePath)
    {
        return $this->postFile($filePath);
    }

    /**
     * @{inheritdoc}
     */
    public function postFile($filePath)
    {
        if (empty($this->tokenKey)) {
            throw new ClientAuthenticationException("No token found. Cannot upload a file without a token.");
        }

        if (!file_exists($filePath)) {
            throw new \RuntimeException(sprintf("File %s does not exist.", $filePath));
        } elseif (!is_readable($filePath)) {
            throw new \RuntimeException(sprintf("File %s is not readable.", $filePath));
        }

        $params = [
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => fopen($filePath, 'r'),
                ],
            ],
        ];

        try {
            $response = $this->post('/file', $params);
            return json_decode($response->getBody(), true);
            // @codeCoverageIgnoreStart
        } catch(GuzzleRequestException $e) {
            throw new ClientRequestException(
                sprintf("Request to /file failed. Status: %s. Error message: %s", $e->getResponse()->getStatusCode(), $e->getMessage())
            );
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @{inheritdoc}
     */
    public function getCurriculaMappingSuggestions($from, $to, $termId)
    {
        if (empty($this->tokenKey)) {
            throw new ClientAuthenticationException("No token found. Cannot load partner statistics without a token.");
        }

        try {
            // Prepare the URL arguments.
            $from = urlencode($from);
            $to = urlencode($to);
            $termId = urlencode($termId);

            $response = $this->get("/curriculum/map/$from/$to/$termId");
            if ($response->getStatusCode() == 200) {
                return json_decode($response->getBody(), true);
            } else {
                throw new ClientRequestException(
                    sprintf("Request to /curriculum/map/$from/$to/$termId failed. Status: %s. Error message: %s", $response->getStatusCode(), $response->getBody()),
                    $response->getStatusCode()
                );
            }
            // @codeCoverageIgnoreStart
        } catch(GuzzleRequestException $e) {
            throw new ClientRequestException(
                sprintf("Request to /curriculum/map/$from/$to/$termId failed. Status: %s. Error message: %s", $e->getResponse()->getStatusCode(), $e->getMessage())
            );
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @{inheritdoc}
     */
    public function postDescriptionAction($id, $actionType, array $actionParams = null)
    {
        if (empty($this->tokenKey)) {
            throw new ClientAuthenticationException("No token found. Cannot create an action without a token.");
        }

        $payload = [
            'type' => $actionType,
        ];

        if (!empty($actionParams)) {
            $payload['params'] = $actionParams;
        }

        try {
            $params = [
                'body' => json_encode($payload),
            ];
            $response = $this->post('/description/' . urlencode($id) . '/action', $params);
            return json_decode($response->getBody(), true);
            // @codeCoverageIgnoreStart
        } catch(GuzzleRequestException $e) {
            throw new ClientRequestException(
                sprintf("Request to /description/%s/action failed. Status: %s. Error message: %s", $id, $e->getResponse()->getStatusCode(), $e->getMessage())
            );
            // @codeCoverageIgnoreEnd
        }
    }

}

