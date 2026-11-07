import { CartProvider } from "@/features/shop/cart";
import { ShopShell } from "@/features/shop/components/ShopShell";

export default function ShopLayout({ children }: { children: React.ReactNode }) {
  return (
    <CartProvider>
      <ShopShell>{children}</ShopShell>
    </CartProvider>
  );
}
