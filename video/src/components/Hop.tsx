// The logo, "hop hop hop": the drawing and its motion dashes, scaled by 3/4 to make room for the dashes. Render it at
// 4/3 of the size of a picto and the drawing keeps its size. Keep in sync with templates/components/Icon.html.twig ("hop")
export const Hop = ({ size = 64, color = 'currentColor', strokeWidth = 1.8 }: { size?: number; color?: string; strokeWidth?: number }) => (
    <svg viewBox="0 0 24 24" width={size} height={size} fill="none" stroke={color} strokeWidth={strokeWidth} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
        <g transform="translate(3 3) scale(0.75)">
            <path d="M12 2.5v3" />
            <path d="M12 5.5c-3.2 0-5 2.6-5 6.2 0 4.6 2.4 8.3 5 9.8 2.6-1.5 5-5.2 5-9.8 0-3.6-1.8-6.2-5-6.2z" />
            <path d="M7.4 9.8c1.6 1.1 3 1.3 4.6 1.3s3-.2 4.6-1.3" />
            <path d="M7.2 13.6c1.7 1.2 3.2 1.5 4.8 1.5s3.1-.3 4.8-1.5" />
            <path d="M8.3 17.3c1.3.9 2.5 1.1 3.7 1.1s2.4-.2 3.7-1.1" />
            <path d="M12 5.5v15.8" />
            <path d="M12 4c2-2 4.6-2 6.2-.9-.6 2.3-3 3.4-5.2 2.9" />
            <path d="M2.37 8.39.68 7.78M2.37 15.41.68 16.02M21.63 8.39 23.32 7.78M21.63 15.41 23.32 16.02" />
        </g>
    </svg>
);
