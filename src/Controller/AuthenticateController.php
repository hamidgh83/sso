<?php

namespace App\Controller;

use Exception;
use SSO\Exception\NotAttachedException;
use SSO\Service\Broker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AuthenticateController extends AbstractController
{
    /**
     * @Route("/auth/login", name="auth-login")
     */
    public function login()
    {
        $broker = new Broker(getenv('SSO_SERVER'), getenv('SSO_BROKER_ID'), getenv('SSO_BROKER_SECRET'));
        
        $broker->attach(true);

        try {
            if (!empty($_GET['logout'])) {
                $broker->logout();
            } elseif ($broker->getUserInfo() || ($_SERVER['REQUEST_METHOD'] == 'POST' && $broker->login($_POST['username'], $_POST['password']))) {
                header("Location: index.php", true, 303);
                exit;
            }

            if ($_SERVER['REQUEST_METHOD'] == 'POST') $errmsg = "Login failed";
        } catch (NotAttachedException $e) {
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        } catch (Exception $e) {
            $errmsg = $e->getMessage();
        }

        return $this->render('authenticate/login.html.twig', [
            
        ]);
    }
}
