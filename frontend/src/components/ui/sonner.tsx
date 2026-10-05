import { Toaster as SonnerToaster, toast as sonnerToast } from "sonner";
import React from "react";

type ToasterProps = React.ComponentProps<typeof SonnerToaster>;

export const Toaster = ({ ...props }: ToasterProps) => {
  return (
    <SonnerToaster
      className="toaster group"
      toastOptions={{
        classNames: {
          toast:
            "group toast group-[.toaster]:bg-background group-[.toaster]:text-foreground group-[.toaster]:border-border group-[.toaster]:shadow-lg",
          description: "group-[.toast]:text-muted-foreground",
          actionButton: "group-[.toast]:bg-primary group-[.toast]:text-primary-foreground",
          cancelButton: "group-[.toast]:bg-muted group-[.toast]:text-muted-foreground",
        },
      }}
      {...props}
    />
  );
};

// Compatible toast helper supporting both object options and method calls
export const toast = Object.assign(
  (messageOrOptions: any, options?: any) => {
    if (typeof messageOrOptions === 'object' && messageOrOptions !== null) {
      const { title, description, variant } = messageOrOptions;
      if (variant === 'destructive') {
        return sonnerToast.error(title || 'Error', { description });
      }
      return sonnerToast(title || '', { description });
    }
    return sonnerToast(messageOrOptions, options);
  },
  sonnerToast
);
