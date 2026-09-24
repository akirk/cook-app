<?php

namespace CookApp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Markdown {
    public static function to_html( string $markdown ): string {
        $lines = preg_split( "/\r\n|\r|\n/", trim( $markdown ) );
        if ( ! is_array( $lines ) || $lines === [ '' ] ) {
            return '';
        }

        $html = '';
        $paragraph = [];
        $list_type = '';
        $list_items = [];

        $flush_paragraph = static function() use ( &$html, &$paragraph ) {
            if ( ! $paragraph ) {
                return;
            }

            $html .= '<p>' . self::inline( implode( ' ', $paragraph ) ) . '</p>';
            $paragraph = [];
        };

        $flush_list = static function() use ( &$html, &$list_type, &$list_items ) {
            if ( ! $list_type || ! $list_items ) {
                $list_type = '';
                $list_items = [];
                return;
            }

            $tag = $list_type === 'ol' ? 'ol' : 'ul';
            $html .= '<' . $tag . '><li>' . implode( '</li><li>', array_map( [ self::class, 'inline' ], $list_items ) ) . '</li></' . $tag . '>';
            $list_type = '';
            $list_items = [];
        };

        foreach ( $lines as $line ) {
            $line = rtrim( (string) $line );
            if ( trim( $line ) === '' ) {
                $flush_paragraph();
                $flush_list();
                continue;
            }

            if ( preg_match( '/^(#{1,4})\s+(.+)$/', $line, $matches ) ) {
                $flush_paragraph();
                $flush_list();
                $level = min( 6, strlen( $matches[1] ) + 1 );
                $html .= '<h' . $level . '>' . self::inline( trim( $matches[2] ) ) . '</h' . $level . '>';
                continue;
            }

            if ( preg_match( '/^\s*[-*+]\s+(.+)$/', $line, $matches ) ) {
                $flush_paragraph();
                if ( $list_type && $list_type !== 'ul' ) {
                    $flush_list();
                }
                $list_type = 'ul';
                $list_items[] = trim( $matches[1] );
                continue;
            }

            if ( preg_match( '/^\s*\d+[.)]\s+(.+)$/', $line, $matches ) ) {
                $flush_paragraph();
                if ( $list_type && $list_type !== 'ol' ) {
                    $flush_list();
                }
                $list_type = 'ol';
                $list_items[] = trim( $matches[1] );
                continue;
            }

            $flush_list();
            $paragraph[] = trim( $line );
        }

        $flush_paragraph();
        $flush_list();

        return $html;
    }

    public static function strip_images( string $markdown ): string {
        return preg_replace( '/!\[([^\]]*)\]\([^)]+\)/', '$1', $markdown );
    }

    private static function inline( string $text ): string {
        $text = self::strip_images( $text );
        $codes = [];
        $text = preg_replace_callback( '/`([^`]+)`/', static function( array $matches ) use ( &$codes ) {
            $placeholder = "\x1A" . count( $codes ) . "\x1A";
            $codes[ $placeholder ] = '<code>' . esc_html( $matches[1] ) . '</code>';
            return $placeholder;
        }, $text );

        $text = esc_html( $text );
        $text = preg_replace_callback( '/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', static function( array $matches ) {
            return '<a href="' . esc_url( $matches[2] ) . '">' . esc_html( $matches[1] ) . '</a>';
        }, $text );
        $text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
        $text = preg_replace( '/__([^_]+)__/', '<strong>$1</strong>', $text );
        $text = preg_replace( '/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text );
        $text = preg_replace( '/(?<!_)_([^_]+)_(?!_)/', '<em>$1</em>', $text );

        return strtr( $text, $codes );
    }
}
