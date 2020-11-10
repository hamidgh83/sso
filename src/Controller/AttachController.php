<?php

namespace App\Controller;

use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations\Route;
use App\Service\Server;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Desarrolla2\Cache\File as FileCache;
use Loggy;
use App\Service\ExceptionInterface as SSOException;

class AttachController extends AbstractFOSRestController
{
    /**
     * @Route("/attach", name="attach")
     */
    public function index(Request $request, LoggerInterface $logger)
    {
        $brokers = $this->getParameter("brokers");
        // Instantiate the SSO server.
        $ssoServer = (new Server(
            function (string $id) use ($brokers) {
                return $brokers[$id] ?? null;  // Callback to get the broker secret. You might fetch this from DB.
            },
            new FileCache(sys_get_temp_dir())            // Any PSR-16 compatible cache
        ))->withLogger(new Loggy('SSO'));

        try {
            // Attach the broker token to the user session. Uses query parameters from $_GET.
            $verificationCode = $ssoServer->attach();
            $error = null;
        } catch (SSOException $exception) {
            $verificationCode = null;
            $error = ['code' => $exception->getCode(), 'message' => $exception->getMessage()];
        }

        $returnType =
            ($request->get('return_url') ? 'redirect' : null) ??
            ($request->get('callback') ? 'jsonp' : null) ??
            (strpos($_SERVER['HTTP_ACCEPT'], 'text/html') !== false ? 'html' : null) ??
            (strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false ? 'json' : null);

        switch ($returnType) {
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
                
                return $this->redirect($url);

            default:
                http_response_code(400);
                header('Content-Type: text/plain');
                echo "Missing 'return_url' query parameter";
                break;
        }

        return $this->view(['verificationCode' => $verificationCode], 200);
    }
}
