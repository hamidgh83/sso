<?php

namespace App\Controller;

use App\Exception\BrokerException;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use App\Service\Server;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AttachController extends AbstractFOSRestController
{
    /**
     * @Rest\Get("/attach", name="attach")
     */
    public function index(Request $request, LoggerInterface $logger)
    {
        $brokers = $this->getParameter("brokers");
        $server  = new Server(function (string $id) use ($brokers) {
            return $brokers[$id];  // Callback to get the broker secret. You might fetch this from DB.
        }, $logger);

        try {
            $verificationCode = $server->attach();
        } catch (BrokerException $e) {
            throw new HttpException(Response::HTTP_NOT_ACCEPTABLE, $e->getMessage());
        }

        $returnType =
            ($request->get('return_url') ? 'redirect' : null) ??
            ($request->get('callback') ? 'jsonp' : null) ??
            (strpos($_SERVER['HTTP_ACCEPT'], 'text/html') !== false ? 'html' : null) ??
            (strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false ? 'json' : null);

        /* switch ($returnType) {
            case 'json':
                header('Content-type: application/json');
                http_response_code($error['code'] ?? 200);
                echo json_encode($error ?? ['verify' => $verificationCode]);
                break;

            case 'jsonp':
                header('Content-type: application/javascript');
                $data = json_encode($error ?? ['verify' => $verificationCode]);
                $responseCode = $error['code'] ?? 200;
                echo $_REQUEST['callback'] . "($data, $responseCode);";
                break;

            case 'redirect':
                $query = isset($error) ? 'sso_error=' . $error['message'] : 'sso_verify=' . $verificationCode;
                $url = $_GET['return_url'] . (strpos($_GET['return_url'], '?') === false ? '?' : '&') . $query;
                header('Location: ' . $url, true, 303);
                echo "You're being redirected to <a href='{$url}'>$url</a>";
                break;

            default:
                http_response_code(400);
                header('Content-Type: text/plain');
                echo "Missing 'return_url' query parameter";
                break;
        } */

        return $this->view(['verificationCode' => $verificationCode], 200);
    }
}
