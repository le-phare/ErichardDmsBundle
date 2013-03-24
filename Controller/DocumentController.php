<?php

namespace Erichard\DmsBundle\Controller;

use Erichard\DmsBundle\Entity\Document;
use Erichard\DmsBundle\Form\DocumentType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\JsonResponse;

class DocumentController extends Controller
{
    public function addAction($node)
    {
        $documentNode = $this->findNodeOr404($node);
        $request      = $this->get('request');

        if (!$filename = $request->query->get('filename')) {
            return $this->render('ErichardDmsBundle:Document:add.html.twig', array(
                'node' => $documentNode
            ));
        } else {

            $document = new Document($documentNode);
            $document->setName($this->get('templating.helper.form')->humanize($filename));
            $document->setFilename($filename);

            $form  = $this->createForm(new DocumentType(), $document);

            return $this->render('ErichardDmsBundle:Document:create.html.twig', array(
                'node' => $documentNode,
                'form' => $form->createView()
            ));
        }
    }

    public function createAction($node)
    {
        $parentNode = $this->findNodeOr404($node);
        $document   = new Document($parentNode);
        $form       = $this->createForm(new DocumentType(), $document);

        $form->bind($this->get('request'));

        if (!$form->isValid()) {
            $response = $this->render('ErichardDmsBundle:Document:create.html.twig', array(
                'node' => $parentNode,
                'form' => $form->createView()
            ));
        } else {
            $newNode        = $form->getData();
            $storageTmpPath = $this->container->getParameter('dms.storage.tmp_path');
            $storagePath    = $this->container->getParameter('dms.storage.path');


            // Compute new filename
            $checksumFile = $storageTmpPath. '/checksum.php';
            if (!is_readable($checksumFile)) {
                throw new \RuntimeException('Unable to read the checksum.php file.');
            }

            $md5sums = require_once $checksumFile;
            $md5 = $md5sums[$newNode->getFilename()]['checksum'];

            $filename = '';
            for ($i = 0; $i <= 3; $i++) {
                $filename .= substr($md5, $i*2, 2).'/';
            }
            $filename .= $md5;

            $filesystem = $this->get('filesystem');

            // move file
            if (!$filesystem->exists(dirname($storagePath. '/' .$filename))) {
                $filesystem->mkdir(dirname($storagePath. '/' .$filename));
            }

            $filesystem->rename($md5sums[$newNode->getFilename()]['file'], $storagePath. '/' .$filename);

            $newNode->setFilename($filename);

            $em = $this->get('doctrine')->getManager();
            $em->persist($newNode);
            $em->flush();

            $this->get('session')->getFlashBag()->add('success', 'New document successfully created !');

            $response = $this->redirect($this->generateUrl('erichard_dms_node_list', array('node' => $node)));
        }

        return $response;
    }

    public function uploadAction()
    {
        $response = new JsonResponse();
        $response->expire();
        $response->setLastModified(new \DateTime());
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate', true);
        $response->headers->set('Cache-Control', 'post-check=0, pre-check=0', false);
        $response->headers->set('Pragma', 'no-cache');

        $request = $this->get('request');

        // Settings
        $targetDir = $this->container->getParameter('dms.storage.tmp_path');

        $cleanupTargetDir = true; // Remove old files
        $maxFileAge = 5 * 3600; // Temp file age in seconds

        // 5 minutes execution time
        @set_time_limit(5 * 60);

        // Get parameters
        $chunk = $request->request->getInt('chunk', 0);
        $chunks = $request->request->getInt('chunks', 0);
        $origFileName = $request->request->get('name', '');

        if ('' === $origFileName) {
            $response->setData(array(
                'jsonrpc' => '2.0',
                'id'      => 'id',
                'error'   => array(
                    'code'    => 105,
                    'message' => "Failed to read filename from the request.",
                )
            ));

            return $response;
        }

        // Clean the fileName for security reasons
        $fileName = preg_replace('/[^\w\._]+/', '_', $origFileName);

        // Make sure the fileName is unique but only if chunking is disabled
        if ($chunks < 2 && is_file($targetDir . DIRECTORY_SEPARATOR . $fileName)) {
            $fileinfo = pathinfo($fileName);

            $count = 1;
            while (is_file($targetDir . DIRECTORY_SEPARATOR . $fileinfo['filename'] . '_' . $count . '.' . $fileinfo['extension'])) {
                $count++;
            }

            $fileName = $fileinfo['filename'] . '_' . $count . '.' . $fileinfo['extension'];
        }

        $filePath = $targetDir . DIRECTORY_SEPARATOR . $fileName;

        // Create target dir
        if (!is_dir($targetDir)) {
            @mkdir($targetDir);
        }

        // Remove old temp files
        if ($cleanupTargetDir) {
            if (is_dir($targetDir) && ($dir = opendir($targetDir))) {
                while (($file = readdir($dir)) !== false) {
                    $tmpfilePath = $targetDir . DIRECTORY_SEPARATOR . $file;

                    // Remove temp file if it is older than the max age and is not the current file
                    if (preg_match('/\.part$/', $file) && (filemtime($tmpfilePath) < time() - $maxFileAge) && ($tmpfilePath != "{$filePath}.part")) {
                        @unlink($tmpfilePath);
                    }
                }
                closedir($dir);
            } else {
                $response->setData(array(
                    'jsonrpc' => '2.0',
                    'id'      => 'id',
                    'error'   => array(
                        'code'    => 100,
                        'message' => "Failed to open temp directory.",
                    )
                ));

                return $response;
            }
        }


        // Look for the content type header
        $contentType = $request->headers->get('CONTENT_TYPE', $request->headers->get('HTTP_CONTENT_TYPE'));

        // Handle non multipart uploads older WebKit versions didn't support multipart in HTML5
        if (strpos($contentType, "multipart") !== false) {
            if (isset($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
                // Open temp file
                $out = @fopen("{$filePath}.part", $chunk == 0 ? "wb" : "ab");
                if ($out) {
                    // Read binary input stream and append it to temp file
                    $in = @fopen($_FILES['file']['tmp_name'], "rb");

                    if ($in) {
                        while ($buff = fread($in, 4096))
                            fwrite($out, $buff);
                    } else {
                        $response->setData(array(
                            'jsonrpc' => '2.0',
                            'id'      => 'id',
                            'error'   => array(
                                'code'    => 101,
                                'message' => "Failed to open input stream.",
                            )
                        ));
                        return $response;
                    }
                    @fclose($in);
                    @fclose($out);
                    @unlink($_FILES['file']['tmp_name']);
                } else {
                    $response->setData(array(
                        'jsonrpc' => '2.0',
                        'id'      => 'id',
                        'error'   => array(
                            'code'    => 102,
                            'message' => "Failed to open output stream.",
                        )
                    ));
                    return $response;
                }
            } else {
                $response->setData(array(
                    'jsonrpc' => '2.0',
                    'id'      => 'id',
                    'error'   => array(
                        'code'    => 103,
                        'message' => "Failed to move uploaded file.",
                    )
                ));
                return $response;
            }
        } else {
            // Open temp file
            $out = @fopen("{$filePath}.part", $chunk == 0 ? "wb" : "ab");
            if ($out) {
                // Read binary input stream and append it to temp file
                $in = @fopen("php://input", "rb");

                if ($in) {
                    while ($buff = fread($in, 4096))
                        fwrite($out, $buff);
                } else {
                    $response->setData(array(
                        'jsonrpc' => '2.0',
                        'id'      => 'id',
                        'error'   => array(
                            'code'    => 101,
                            'message' => "Failed to open input stream.",
                        )
                    ));
                    return $response;
                }

                @fclose($in);
                @fclose($out);
            } else {
                $response->setData(array(
                    'jsonrpc' => '2.0',
                    'id'      => 'id',
                    'error'   => array(
                        'code'    => 102,
                        'message' => "Failed to open output stream.",
                    )
                ));
                return $response;
            }
        }

        // Check if file has been uploaded
        if (!$chunks || $chunk == $chunks - 1) {
            $md5 = md5_file("{$filePath}.part");

            if (is_file($targetDir. '/checksum.php')) {
                $md5sum = require_once $targetDir. '/checksum.php';
            } else {
                $md5sum = array();
            }

            $md5sum[$origFileName] = array(
                'checksum' => $md5,
                'file' => $filePath
            );

            file_put_contents($targetDir. '/checksum.php', "<?php\n\nreturn " . var_export($md5sum, true) . ';');

            // Strip the temp .part suffix off
            rename("{$filePath}.part", $filePath);
        }

        $response->setData(array(
            'jsonrpc' => '2.0',
            'result'  => null,
            'id'      => 'id',
        ));

        return $response;
    }

    protected function findNodeOr404($slug)
    {
        $documentNode = $this
            ->get('doctrine')
            ->getRepository('Erichard\DmsBundle\Entity\DocumentNode')
            ->findBySlugWithChildren($slug)
        ;

        if (null == $documentNode) {
            throw $this->createNotFoundException(sprintf('Document not found : %s', $slug));
        }

        return $documentNode;
    }
}
