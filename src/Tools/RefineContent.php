<?php

/**
 * @license LGPL, https://opensource.org/license/lgpl-3-0
 */


namespace Aimeos\Cms\Tools;

use Aimeos\Cms\Utils;
use Aimeos\Cms\Permission;
use Aimeos\Cms\Models\Page;
use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Schema\Schema;
use Aimeos\Prisma\Tools;
use Aimeos\Prisma\Exceptions\PrismaException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Response;
use Laravel\Mcp\Request;


#[Name('refine-content')]
#[Title('Refine page content using AI')]
#[Description('Improves or restructures existing page content using AI based on a prompt. Pass the page ID and a prompt describing the changes. Returns the refined content elements as a JSON array.')]
class RefineContent extends Tool
{
    /**
     * Field types the AI must not set because their value is structured
     * (grids, item lists, file references) or machine-managed (hidden defaults).
     *
     * @var array<string>
     */
    private const COMPLEX_FIELDS = ['table', 'items', 'images', 'image', 'video', 'audio', 'file', 'media', 'hidden'];


    /**
     * Handle the tool request.
     */
    public function handle( Request $request ): \Laravel\Mcp\ResponseFactory
    {
        if( !Permission::can( 'page:refine', $request->user() ) ) {
            throw new \Aimeos\Cms\Exception( 'Insufficient permissions' );
        }

        $validated = $request->validate([
            'id' => 'required|string|max:36',
            'prompt' => 'required|string|max:2000',
            'context' => 'string|max:30000',
        ], [
            'id.required' => 'You must specify the ID of the page to refine.',
            'prompt.required' => 'You must provide a prompt describing how to refine the content.',
        ] );

        /** @var Page|null $page */
        $page = Page::withTrashed()->select( 'id', 'content', 'latest_id' )
            ->with( ['latest' => fn( $q ) => $q->select( 'id', 'versionable_id', 'aux' )] )
            ->find( $validated['id'] );

        if( !$page ) {
            return Response::structured( ['error' => 'Page not found.'] );
        }

        $content = (array) ( $page->latest?->aux->content ?? $page->content ?? [] );

        $provider = config( 'cms.ai.refine.provider' );
        $config = config( 'cms.ai.refine', [] );
        $model = config( 'cms.ai.refine.model' );

        $system = view( 'cms::prompts.refine' )->render();
        $types = array_keys( \Aimeos\Cms\Schema::schemas( section: 'content' ) );

        try
        {
            $response = Prisma::text()->using( $provider, $config )
                ->model( $model )
                ->withMaxTokens( config( 'cms.ai.maxtoken' ) )
                ->withSystemPrompt( $system . "\n" . ( $validated['context'] ?? '' ) )
                ->withTools( [Tools::provider( 'web_search' ), Tools::provider( 'web_fetch' )] )
                ->withClientOptions( [
                    'timeout' => 180,
                    'connect_timeout' => 10,
                ] )
                ->ensure( 'structure' )
                ->structure( $validated['prompt'] . "\n\nContent as JSON:\n" . json_encode( $content ), $this->schema_response( $types ) ); // @phpstan-ignore-line method.notFound

            $structured = $response->structured();

            if( !$structured ) {
                return Response::structured( ['error' => 'Invalid content in refine response.'] );
            }

            $result = $this->merge( $content, $structured['contents'] ?? [] );

            return Response::structured( ['content' => $result] );
        }
        catch( PrismaException $e )
        {
            throw new \Aimeos\Cms\Exception( $e->getMessage() );
        }
    }


    /**
     * Merges the existing content with the response from the AI.
     *
     * @param array<mixed> $content Existing content elements
     * @param array<mixed> $response AI response with updated text content
     * @return array<mixed> Updated content elements
     */
    protected function merge( array $content, array $response ) : array
    {
        $result = [];
        $map = collect( $content )->keyBy( 'id' );
        $schemas = \Aimeos\Cms\Schema::schemas( section: 'content' );

        foreach( $response as $item )
        {
            $entry = (array) $map->pull( $item['id'], [] );
            $entry['data'] = (array) ( $entry['data'] ?? [] );
            $entry['type'] = $item['type'] ?? ( $entry['type'] ?? 'text' );

            if( !isset( $entry['id'] ) ) {
                $entry['id'] = Utils::uid();
            }

            $fields = $schemas[$entry['type']]['fields'] ?? [];

            foreach( $item['data'] ?? [] as $data )
            {
                $name = $data['name'] ?? '';

                // Allow any non-complex field defined for this element type. Complex
                // fields (table grid, file references, ...) are preserved as-is so the AI
                // can't serialize them into a value or blank them out.
                if( empty( $name ) || empty( $fields[$name] )
                    || in_array( $fields[$name]['type'] ?? 'string', self::COMPLEX_FIELDS, true )
                ) {
                    continue;
                }

                $entry['data'][$name] = (string) ($data['value'] ?? '');
            }

            $result[] = $entry;
        }

        return $result;
    }


    /**
     * Returns the schema for the AI structured response.
     *
     * @param array<string> $types Available content element types
     * @return Schema
     */
    protected function schema_response( array $types ) : Schema
    {
        return Schema::for( 'response', [
            'contents' => Schema::array()->description( 'List of page content elements' )->required()->items(
                Schema::object( [
                    'id' => Schema::string()->description( 'The ID of the content element' )->nullable()->required(),
                    'type' => Schema::string()->description( 'The type of the content element' )->enum( $types )->required(),
                    'data' => Schema::array()->description( 'List of field name/value pairs for the content element' )->required()->items(
                        Schema::object( [
                            'name' => Schema::string()->description( 'Name of the data field to set, e.g. "title", "text", "level", "header" or "language"' )->required(),
                            'value' => Schema::string()->description( 'Field value as plain string: title, markdown text, source code, or a scalar option, number or boolean' )->required(),
                        ] )
                    ),
                ] )
            ),
        ] );
    }


    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema( JsonSchema $schema ) : array
    {
        return [
            'id' => $schema->string()
                ->description('The UUID of the page whose content to refine.')
                ->required(),
            'prompt' => $schema->string()
                ->description('Describe how to improve the content, e.g., "Make the text more engaging and add subheadings" or "Rewrite for a technical audience".')
                ->required(),
            'context' => $schema->string()
                ->description('Additional context such as target audience, tone, or brand guidelines.'),
        ];
    }


    /**
     * Determine if the tool should be registered.
     *
     * @param Request $request The incoming request to check permissions for.
     * @return bool TRUE if the tool should be registered, FALSE otherwise.
     */
    public function shouldRegister( Request $request ) : bool
    {
        return Permission::can( 'page:refine', $request->user() );
    }
}
