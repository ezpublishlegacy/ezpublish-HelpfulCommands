<?php

namespace Gaz\MiscBundle\Command;

use eZ\Publish\Core\FieldType\XmlText\Input\EzXml;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand,
    Symfony\Component\Console\Input\InputInterface,
    Symfony\Component\Console\Output\OutputInterface,
    Symfony\Component\Console\Input\InputArgument,
    Symfony\Component\Console\Input\InputOption;

/**
 * Class RemoveLinksFromContentCommand
 *
 * Takes a list of ContentId's and republishes them, removing any links
 * Used to fix an issue whereby all ezurl links were somehow deleted from the database.
 *
 * You are probably wondering how such bizarre destructive code would ever be useful? 
 * Well: there is a sweet bug whereby ez will happily delete every single
 * hyperlink in the content database without warning.
 * 
 * After the links are deleted, exceptions are thrown whenever content
 * containing <link url_id="123">link</link> is called. url_id 123 cannot be found!
 *
 * Sysadmins on holiday, so no access to db backups. Site collapsing. 4000 missing hyperlinks
 * DELETE ALL THE LINKS! and pretend they never existed.
 *
 * Thankfully this was never run on prod. But it was a close call.
 *
 * SQL TO FIND THE LINKS (1-2s for 1mil rows):
 *
 * SELECT contentobject_id FROM  ezcontentobject_attribute WHERE  data_text LIKE  '%<link %';
 *
 * @package Gaz\MiscBundle\Command
 */
class RemoveLinksFromContentCommand extends ContainerAwareCommand
{

    protected function configure()
    {
        $this->setName( 'migration:remove_links' )->setDefinition(
            array(
                new InputArgument( 'contentIds' , InputArgument::REQUIRED, 'the content to be updated' ),
            ))
            ->setDescription( 'Deletes all references to <links> in the data_text attributes of the given content.' )
            ->setHelp(
                <<<EOT
<question>EXAMPLE SQL TO GENERATE ID LIST</question>
<comment>SELECT contentobject_id FROM  ezcontentobject_attribute WHERE  data_text LIKE  '%<link %';</comment>
EOT
            );

    protected function execute( InputInterface $input, OutputInterface $output )
    {
        /** @var $repository \eZ\Publish\API\Repository\Repository */
        $repository = $this->getContainer()->get( 'ezpublish.api.repository' );
        $contentService = $repository->getContentService();

        $repository->setCurrentUser( $repository->getUserService()->loadUser( 14 ) );

        $contentIds = $input->getArgument( 'contentIds' );
        $contentIds = explode( ',', $contentIds );
        $contentIds = array_filter( $contentIds );

        // @todo this will only work for xml fields called 'body'...
        foreach( $contentIds as $contentId ) {
            try {
                // create a content draft from the current published version
                $content = $contentService->loadContent( $contentId );
                $contentXml = $content->getFieldValue( 'body' );

                // make sure phpstorm knows the object type so the little popups work
                if( !$contentXml->xml instanceof \DOMDocument ){
                    continue;
                }
                // only want da links
                if( $contentXml->xml->getElementsByTagName( 'link' )->length == 0 ){
                    continue;
                }
                // I've always thought the naming of saveXML was odd. domDocumentToString is it's actual purpose.
                $contentXmlString = $contentXml->xml->saveXML( $contentXml->xml );

                /**
                 * Yes. I am parsing xml with regex. And yes, I know how that makes you feel.
                 */

                // e.g. <link url_id="30351">
                $contentXmlString = preg_replace( '/\<link[\s]+url\_id\=\"[\d]+\"\>/i', '', $contentXmlString );
                // e.g. </link>
                $contentXmlString = preg_replace( '/\<\/link\>/i', '', $contentXmlString );

                /**
                 * Two lines to strip all <link> tags from content,
                 * whilst maintaining all other formatting,
                 * with no complicated loops, appendages or removals.
                 */

                $cleanEzxmlInput = new EzXml( $contentXmlString );

                $contentInfo = $contentService->loadContentInfo( $contentId );
                $contentDraft = $contentService->createContentDraft( $contentInfo );
                $contentUpdateStruct = $contentService->newContentUpdateStruct();

                // updates the content
                $contentUpdateStruct->setField( 'body', $cleanEzxmlInput );

                $contentDraft = $contentService->updateContent( $contentDraft->versionInfo, $contentUpdateStruct );
                $content = $contentService->publishVersion( $contentDraft->versionInfo );
                $output->write( $contentId );
                $output->writeln(" - content updated.");
            }
            //  if the content with the given id does not exist
            catch (\eZ\Publish\API\Repository\Exceptions\NotFoundException $e) {
                $output->writeln($e->getMessage());
            }
            //  if the user is not allowed to update this version
            catch (\eZ\Publish\API\Repository\Exceptions\UnauthorizedException $e) {
                $output->writeln($e->getMessage());
            }
            // if the version is not a draft
            catch (\eZ\Publish\API\Repository\Exceptions\BadStateException $e) {
                $output->writeln($e->getMessage());
            }
            // if a field in the $contentUpdateStruct is not valid
            catch (\eZ\Publish\API\Repository\Exceptions\ContentFieldValidationException $e) {
                $output->writeln($e->getMessage());
            }
            // if a required field is set to an empty value
            catch (\eZ\Publish\API\Repository\Exceptions\ContentValidationException $e) {
                $output->writeln($e->getMessage());
            }
            // if a field value is not accepted by the field type
            catch (\eZ\Publish\API\Repository\Exceptions\InvalidArgumentException $e) {
                $output->writeln($e->getMessage());
            }

        }
    }
}
